<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\Producto;
use App\Models\Staff;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrdenService
{
    use ResolvesNegocioFromActor;

    private const STAFF_WITH = 'id,negocio_id,username,sucursal_id,empleado_id,status';

    /**
     * Crea orden + detalles (flujo POS "Cobrar").
     *
     * @param  array{
     *     customer_name: string,
     *     sucursal_id?: int|null,
     *     payment_type: string,
     *     status?: int,
     *     detalles: list<array{
     *         producto_id: int,
     *         product_name?: string|null,
     *         quantity: float|int|string,
     *         price?: float|int|string|null,
     *         extras?: array|null,
     *         notes?: string|null,
     *         status?: int
     *     }>
     * }  $data
     */
    public function create(Negocio $negocio, User|Staff $actor, array $data): Orden
    {
        $sucursalId = $this->resolveSucursalId($negocio, $actor, $data['sucursal_id'] ?? null);

        if (! $negocio->sucursales()->whereKey($sucursalId)->exists()) {
            throw new HttpException(422, 'La sucursal no pertenece a tu negocio.');
        }

        $paymentType = $this->normalizePaymentType($data['payment_type']);
        $status = (int) ($data['status'] ?? Orden::STATUS_PAGADA);

        if (! in_array($status, Orden::STATUSES, true)) {
            throw new HttpException(422, 'Estatus de orden inválido.');
        }

        return DB::transaction(function () use ($negocio, $actor, $data, $sucursalId, $paymentType, $status) {
            $auditId = $this->auditUserId($actor, $negocio);
            $orderNumber = $this->nextOrderNumber($negocio);
            $lineRows = $this->buildDetalleRows($negocio, $data['detalles']);
            $total = collect($lineRows)->sum(
                fn (array $row) => (float) $row['quantity'] * (float) $row['price']
            );

            $orden = $negocio->ordenes()->create([
                'order_number' => $orderNumber,
                'sucursal_id' => $sucursalId,
                'customer_name' => $data['customer_name'],
                'payment_type' => $paymentType,
                'total' => round($total, 2),
                'status' => $status,
                'created_by_staff_id' => $actor instanceof Staff ? $actor->id : null,
                'created_by' => $auditId,
                'updated_by' => $auditId,
            ]);

            foreach ($lineRows as $row) {
                $orden->detalles()->create($row);
            }

            return $orden->load($this->ordenRelations());
        });
    }

    public function listForNegocio(
        Negocio $negocio,
        int $perPage = 15,
        ?int $sucursalId = null,
        ?int $status = null,
    ): LengthAwarePaginator {
        $query = $negocio->ordenes()
            ->with([
                'sucursal:id,negocio_id,type,name',
                'detalles.advancedByStaff:'.self::STAFF_WITH,
                'detalles.finishedByStaff:'.self::STAFF_WITH,
                'createdByStaff:'.self::STAFF_WITH,
                'advancedByStaff:'.self::STAFF_WITH,
                'finishedByStaff:'.self::STAFF_WITH,
                'createdBy:id,name,email',
            ])
            ->latest('id');

        if ($sucursalId) {
            $query->where('sucursal_id', $sucursalId);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    public function findForNegocio(Negocio $negocio, int $ordenId): Orden
    {
        return $negocio->ordenes()
            ->with($this->ordenRelations())
            ->findOrFail($ordenId);
    }

    public function setStatus(Orden $orden, User|Staff $actor, int $status): Orden
    {
        if (! in_array($status, Orden::STATUSES, true)) {
            throw new HttpException(422, 'Estatus de orden inválido.');
        }

        $orden->status = $status;
        $orden->updated_by = $this->auditUserId($actor, $orden->negocio);
        $this->applyOrdenStaffTracking($orden, $actor, $status);
        $orden->save();

        return $orden->refresh()->load($this->ordenRelations());
    }

    public function setDetalleStatus(
        Orden $orden,
        int $detalleId,
        User|Staff $actor,
        int $status,
    ): OrdenDetalle {
        if (! in_array($status, OrdenDetalle::STATUSES, true)) {
            throw new HttpException(422, 'Estatus de detalle inválido.');
        }

        $detalle = $orden->detalles()->whereKey($detalleId)->firstOrFail();
        $detalle->status = $status;
        $this->applyDetalleStaffTracking($detalle, $actor, $status);
        $detalle->save();

        $orden->updated_by = $this->auditUserId($actor, $orden->negocio);
        // Si cocina avanza/finaliza un producto, también deja huella en la orden
        if ($actor instanceof Staff) {
            if (in_array($status, OrdenDetalle::ADVANCE_STATUSES, true) && ! $orden->advanced_by_staff_id) {
                $orden->advanced_by_staff_id = $actor->id;
                $orden->advanced_at = now();
                if ($orden->status === Orden::STATUS_PAGADA) {
                    $orden->status = Orden::STATUS_EN_COCINA;
                }
            }

            if (in_array($status, OrdenDetalle::FINISH_STATUSES, true)) {
                if (! $orden->advanced_by_staff_id) {
                    $orden->advanced_by_staff_id = $actor->id;
                    $orden->advanced_at = $orden->advanced_at ?? now();
                }
            }
        }
        $orden->save();

        return $detalle->refresh()->load([
            'producto:id,negocio_id,name,price',
            'advancedByStaff:'.self::STAFF_WITH,
            'finishedByStaff:'.self::STAFF_WITH,
        ]);
    }

    /**
     * @return list<string>
     */
    private function ordenRelations(): array
    {
        return [
            'sucursal:id,negocio_id,type,name',
            'detalles.producto:id,negocio_id,name,price',
            'detalles.advancedByStaff:'.self::STAFF_WITH,
            'detalles.finishedByStaff:'.self::STAFF_WITH,
            'createdByStaff:'.self::STAFF_WITH,
            'advancedByStaff:'.self::STAFF_WITH,
            'finishedByStaff:'.self::STAFF_WITH,
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ];
    }

    private function applyOrdenStaffTracking(Orden $orden, User|Staff $actor, int $status): void
    {
        if (! $actor instanceof Staff) {
            return;
        }

        if (in_array($status, Orden::ADVANCE_STATUSES, true)) {
            $orden->advanced_by_staff_id = $actor->id;
            $orden->advanced_at = now();
        }

        if (in_array($status, Orden::FINISH_STATUSES, true)) {
            if (! $orden->advanced_by_staff_id) {
                $orden->advanced_by_staff_id = $actor->id;
                $orden->advanced_at = $orden->advanced_at ?? now();
            }
            $orden->finished_by_staff_id = $actor->id;
            $orden->finished_at = now();
        }
    }

    private function applyDetalleStaffTracking(OrdenDetalle $detalle, User|Staff $actor, int $status): void
    {
        if (! $actor instanceof Staff) {
            return;
        }

        if (in_array($status, OrdenDetalle::ADVANCE_STATUSES, true)) {
            $detalle->advanced_by_staff_id = $actor->id;
            $detalle->advanced_at = now();
        }

        if (in_array($status, OrdenDetalle::FINISH_STATUSES, true)) {
            if (! $detalle->advanced_by_staff_id) {
                $detalle->advanced_by_staff_id = $actor->id;
                $detalle->advanced_at = $detalle->advanced_at ?? now();
            }
            $detalle->finished_by_staff_id = $actor->id;
            $detalle->finished_at = now();
        }
    }

    private function nextOrderNumber(Negocio $negocio): int
    {
        $last = $negocio->ordenes()
            ->lockForUpdate()
            ->max('order_number');

        return ((int) $last) + 1;
    }

    private function resolveSucursalId(Negocio $negocio, User|Staff $actor, mixed $sucursalId): int
    {
        if ($sucursalId !== null && $sucursalId !== '') {
            return (int) $sucursalId;
        }

        if ($actor instanceof Staff) {
            return (int) $actor->sucursal_id;
        }

        throw new HttpException(422, 'La sucursal es obligatoria.');
    }

    private function normalizePaymentType(string $paymentType): string
    {
        $normalized = strtolower(trim($paymentType));

        if ($normalized === 'tranferencia') {
            $normalized = 'transferencia';
        }

        if (! in_array($normalized, Orden::PAYMENT_TYPES, true)) {
            throw new HttpException(422, 'Tipo de pago inválido. Usa: credito, transferencia o efectivo.');
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $detalles
     * @return list<array<string, mixed>>
     */
    private function buildDetalleRows(Negocio $negocio, array $detalles): array
    {
        if ($detalles === []) {
            throw new HttpException(422, 'La orden debe incluir al menos un producto.');
        }

        $rows = [];

        foreach ($detalles as $item) {
            $productoId = (int) $item['producto_id'];
            /** @var Producto $producto */
            $producto = $negocio->productos()->whereKey($productoId)->first();

            if (! $producto) {
                throw new HttpException(422, "El producto {$productoId} no pertenece a tu negocio.");
            }

            $quantity = (float) $item['quantity'];
            if ($quantity <= 0) {
                throw new HttpException(422, 'La cantidad debe ser mayor a cero.');
            }

            $price = array_key_exists('price', $item) && $item['price'] !== null
                ? (float) $item['price']
                : (float) $producto->price;

            $detailStatus = (int) ($item['status'] ?? OrdenDetalle::STATUS_PENDIENTE);
            if (! in_array($detailStatus, OrdenDetalle::STATUSES, true)) {
                throw new HttpException(422, 'Estatus de detalle inválido.');
            }

            $rows[] = [
                'producto_id' => $producto->id,
                'product_name' => $item['product_name'] ?? $producto->name,
                'quantity' => $quantity,
                'price' => round($price, 2),
                'extras' => $item['extras'] ?? null,
                'notes' => $item['notes'] ?? null,
                'status' => $detailStatus,
            ];
        }

        return $rows;
    }
}
