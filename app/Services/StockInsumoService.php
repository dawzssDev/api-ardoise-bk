<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Staff;
use App\Models\StockInsumo;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockInsumoService
{
    use ResolvesNegocioFromActor;

    public function findSucursalForNegocio(Negocio $negocio, int $sucursalId): Sucursal
    {
        return $negocio->sucursales()->findOrFail($sucursalId);
    }

    public function findForNegocio(Negocio $negocio, int $stockId): StockInsumo
    {
        return $negocio->stockInsumos()
            ->with([
                'sucursal:id,negocio_id,type,name',
                'insumo:id,negocio_id,name,status_insumo',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->findOrFail($stockId);
    }

    /**
     * Lista insumos del negocio con stock de la sucursal (0 si aún no hay registro).
     */
    public function listForSucursal(Negocio $negocio, Sucursal $sucursal, int $perPage = 15): LengthAwarePaginator
    {
        return $negocio->insumos()
            ->with(['categoria:id,negocio_id,name'])
            ->leftJoin('stock_insumos', function ($join) use ($sucursal) {
                $join->on('insumos.id', '=', 'stock_insumos.insumo_id')
                    ->where('stock_insumos.sucursal_id', '=', $sucursal->id);
            })
            ->select('insumos.*')
            ->addSelect([
                'stock_insumos.id as stock_id',
                'stock_insumos.stock_fisico',
                'stock_insumos.stock_minimo',
                'stock_insumos.created_by as stock_created_by',
                'stock_insumos.updated_by as stock_updated_by',
                'stock_insumos.created_at as stock_created_at',
                'stock_insumos.updated_at as stock_updated_at',
            ])
            ->orderBy('insumos.name')
            ->paginate($perPage)
            ->through(function ($row) use ($negocio, $sucursal) {
                return [
                    'id' => $row->stock_id,
                    'negocio_id' => $negocio->id,
                    'sucursal_id' => $sucursal->id,
                    'insumo_id' => $row->id,
                    'insumo' => [
                        'id' => $row->id,
                        'name' => $row->name,
                        'status_insumo' => (bool) $row->status_insumo,
                        'categoria_insumo_id' => $row->categoria_insumo_id,
                        'categoria' => $row->categoria ? [
                            'id' => $row->categoria->id,
                            'name' => $row->categoria->name,
                        ] : null,
                    ],
                    'stock_fisico' => $row->stock_fisico !== null ? number_format((float) $row->stock_fisico, 3, '.', '') : '0.000',
                    'stock_minimo' => $row->stock_minimo !== null ? number_format((float) $row->stock_minimo, 3, '.', '') : '0.000',
                    'created_by' => $row->stock_created_by,
                    'updated_by' => $row->stock_updated_by,
                    'created_at' => $row->stock_created_at,
                    'updated_at' => $row->stock_updated_at,
                ];
            });
    }

    /**
     * @param  array{sucursal_id: int, insumo_id: int, stock_fisico: float|int|string, stock_minimo: float|int|string}  $data
     */
    public function upsert(Negocio $negocio, User|Staff $user, array $data): StockInsumo
    {
        $sucursal = $this->findSucursalForNegocio($negocio, (int) $data['sucursal_id']);
        $insumo = $negocio->insumos()->findOrFail((int) $data['insumo_id']);
        $auditId = $this->auditUserId($user, $negocio);

        $stock = StockInsumo::query()->firstOrNew([
            'sucursal_id' => $sucursal->id,
            'insumo_id' => $insumo->id,
        ]);

        if (! $stock->exists) {
            $stock->negocio_id = $negocio->id;
            $stock->created_by = $auditId;
        }

        $stock->stock_fisico = $data['stock_fisico'];
        $stock->stock_minimo = $data['stock_minimo'];
        $stock->updated_by = $auditId;
        $stock->save();

        return $stock->refresh()->load([
            'sucursal:id,negocio_id,type,name',
            'insumo:id,negocio_id,name,status_insumo',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    /**
     * @param  array<int, array{insumo_id: int, stock_fisico: float|int|string, stock_minimo: float|int|string}>  $items
     * @return list<StockInsumo>
     */
    public function upsertMany(Negocio $negocio, User|Staff $user, Sucursal $sucursal, array $items): array
    {
        return DB::transaction(function () use ($negocio, $user, $sucursal, $items) {
            $saved = [];

            foreach ($items as $item) {
                $saved[] = $this->upsert($negocio, $user, [
                    'sucursal_id' => $sucursal->id,
                    'insumo_id' => $item['insumo_id'],
                    'stock_fisico' => $item['stock_fisico'],
                    'stock_minimo' => $item['stock_minimo'],
                ]);
            }

            return $saved;
        });
    }

    /**
     * @param  array{stock_fisico?: float|int|string, stock_minimo?: float|int|string}  $data
     */
    public function update(StockInsumo $stock, User|Staff $user, array $data): StockInsumo
    {
        $stock->fill($data);
        $stock->updated_by = $this->auditUserId($user, $stock->negocio);
        $stock->save();

        return $stock->refresh()->load([
            'sucursal:id,negocio_id,type,name',
            'insumo:id,negocio_id,name,status_insumo',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }
}
