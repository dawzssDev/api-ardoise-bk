<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Staff;
use App\Models\StockProducto;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockProductoService
{
    use ResolvesNegocioFromActor;

    public function __construct(
        private readonly PlanLimitService $planLimits,
    ) {}

    public function findSucursalForNegocio(Negocio $negocio, int $sucursalId): Sucursal
    {
        return $negocio->sucursales()->findOrFail($sucursalId);
    }

    public function findForNegocio(Negocio $negocio, int $stockId): StockProducto
    {
        return $negocio->stockProductos()
            ->with([
                'sucursal:id,negocio_id,type,name',
                'producto:id,negocio_id,name,price,image,categoria_producto_id',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->findOrFail($stockId);
    }

    /**
     * Lista productos del negocio con stock de la sucursal (0 si aún no hay registro).
     */
    public function listForSucursal(
        Negocio $negocio,
        Sucursal $sucursal,
        int $perPage = 15,
        ?bool $soloActivos = null,
    ): LengthAwarePaginator {
        $query = $negocio->productos()
            ->with(['categoria:id,negocio_id,name'])
            ->leftJoin('stock_productos', function ($join) use ($sucursal) {
                $join->on('productos.id', '=', 'stock_productos.producto_id')
                    ->where('stock_productos.sucursal_id', '=', $sucursal->id);
            })
            ->select('productos.*')
            ->addSelect([
                'stock_productos.id as stock_id',
                'stock_productos.stock_fisico',
                'stock_productos.stock_minimo',
                'stock_productos.is_active as stock_is_active',
                'stock_productos.created_by as stock_created_by',
                'stock_productos.updated_by as stock_updated_by',
                'stock_productos.created_at as stock_created_at',
                'stock_productos.updated_at as stock_updated_at',
            ]);

        if ($soloActivos === true) {
            $query->where(function ($q) {
                $q->where('stock_productos.is_active', true)
                    ->orWhereNull('stock_productos.id');
            });
        } elseif ($soloActivos === false) {
            $query->where('stock_productos.is_active', false);
        }

        return $query
            ->orderBy('productos.name')
            ->paginate($perPage)
            ->through(function ($row) use ($negocio, $sucursal) {
                $isActive = $row->stock_id !== null
                    ? (bool) $row->stock_is_active
                    : true;

                return [
                    'id' => $row->stock_id,
                    'negocio_id' => $negocio->id,
                    'sucursal_id' => $sucursal->id,
                    'producto_id' => $row->id,
                    'producto' => [
                        'id' => $row->id,
                        'name' => $row->name,
                        'price' => number_format((float) $row->price, 2, '.', ''),
                        'image' => $row->image,
                        'image_url' => $row->imageUrl(),
                        'categoria_producto_id' => $row->categoria_producto_id,
                        'categoria' => $row->categoria ? [
                            'id' => $row->categoria->id,
                            'name' => $row->categoria->name,
                        ] : null,
                    ],
                    'stock_fisico' => $row->stock_fisico !== null ? number_format((float) $row->stock_fisico, 3, '.', '') : '0.000',
                    'stock_minimo' => $row->stock_minimo !== null ? number_format((float) $row->stock_minimo, 3, '.', '') : '0.000',
                    'is_active' => $isActive,
                    'created_by' => $row->stock_created_by,
                    'updated_by' => $row->stock_updated_by,
                    'created_at' => $row->stock_created_at,
                    'updated_at' => $row->stock_updated_at,
                ];
            });
    }

    /**
     * @param  array{sucursal_id: int, producto_id: int, stock_fisico: float|int|string, stock_minimo: float|int|string, is_active?: bool}  $data
     */
    public function upsert(Negocio $negocio, User|Staff $user, array $data): StockProducto
    {
        $sucursal = $this->findSucursalForNegocio($negocio, (int) $data['sucursal_id']);
        $producto = $negocio->productos()->findOrFail((int) $data['producto_id']);
        $auditId = $this->auditUserId($user, $negocio);

        $stock = StockProducto::query()->firstOrNew([
            'sucursal_id' => $sucursal->id,
            'producto_id' => $producto->id,
        ]);

        if (! $stock->exists) {
            $this->planLimits->assertCanCreate(
                $negocio,
                PlanLimitService::RESOURCE_STOCK_PRODUCTOS,
                $sucursal->id,
            );
            $stock->negocio_id = $negocio->id;
            $stock->created_by = $auditId;
            $stock->is_active = true;
        }

        $stock->stock_fisico = $data['stock_fisico'];
        $stock->stock_minimo = $data['stock_minimo'];
        if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
            $stock->is_active = (bool) $data['is_active'];
        }
        $stock->updated_by = $auditId;
        $stock->save();

        return $stock->refresh()->load([
            'sucursal:id,negocio_id,type,name',
            'producto:id,negocio_id,name,price,image,categoria_producto_id',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    /**
     * @param  array<int, array{producto_id: int, stock_fisico: float|int|string, stock_minimo: float|int|string, is_active?: bool}>  $items
     * @return list<StockProducto>
     */
    public function upsertMany(Negocio $negocio, User|Staff $user, Sucursal $sucursal, array $items): array
    {
        return DB::transaction(function () use ($negocio, $user, $sucursal, $items) {
            $saved = [];

            foreach ($items as $item) {
                $payload = [
                    'sucursal_id' => $sucursal->id,
                    'producto_id' => $item['producto_id'],
                    'stock_fisico' => $item['stock_fisico'],
                    'stock_minimo' => $item['stock_minimo'],
                ];

                if (array_key_exists('is_active', $item) && $item['is_active'] !== null) {
                    $payload['is_active'] = (bool) $item['is_active'];
                }

                $saved[] = $this->upsert($negocio, $user, $payload);
            }

            return $saved;
        });
    }

    /**
     * @param  array{stock_fisico?: float|int|string, stock_minimo?: float|int|string, is_active?: bool}  $data
     */
    public function update(StockProducto $stock, User|Staff $user, array $data): StockProducto
    {
        $stock->fill($data);
        $stock->updated_by = $this->auditUserId($user, $stock->negocio);
        $stock->save();

        return $stock->refresh()->load([
            'sucursal:id,negocio_id,type,name',
            'producto:id,negocio_id,name,price,image,categoria_producto_id',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    public function setActive(StockProducto $stock, User|Staff $user, bool $isActive): StockProducto
    {
        return $this->update($stock, $user, ['is_active' => $isActive]);
    }
}
