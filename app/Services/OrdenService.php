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
            $orderNumber = $this->nextOrderNumber($negocio, $sucursalId);
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
        User|Staff $actor,
        int $perPage = 15,
        ?int $sucursalId = null,
        ?int $status = null,
    ): LengthAwarePaginator {
        $resolvedSucursalId = $this->resolveSucursalForQuery(
            $negocio,
            $actor,
            $sucursalId,
            requireForMaestro: false,
        );

        $query = $negocio->ordenes()
            ->with([
                'sucursal:id,negocio_id,type,name',
                'detalles.producto:id,negocio_id,name,price',
                'detalles.advancedByStaff:'.self::STAFF_WITH,
                'detalles.finishedByStaff:'.self::STAFF_WITH,
                'createdByStaff:'.self::STAFF_WITH,
                'advancedByStaff:'.self::STAFF_WITH,
                'finishedByStaff:'.self::STAFF_WITH,
                'createdBy:id,name,email',
            ])
            ->latest('id');

        if ($resolvedSucursalId) {
            $query->where('sucursal_id', $resolvedSucursalId);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    /**
     * Tablero KDS: pedidos activos / en proceso / listos de una sucursal.
     *
     * - Staff: usa su sucursal asignada.
     * - Usuario maestro: debe enviar sucursal_id (selector del front).
     *
     * @return array{
     *     sucursal: array{id: int, type: string, name: string},
     *     nuevo: list<\App\Models\Orden>,
     *     en_preparacion: list<\App\Models\Orden>,
     *     listo: list<\App\Models\Orden>
     * }
     */
    public function kitchenBoard(Negocio $negocio, User|Staff $actor, ?int $sucursalId = null): array
    {
        $resolvedSucursalId = $this->resolveSucursalForQuery(
            $negocio,
            $actor,
            $sucursalId,
            requireForMaestro: true,
        );

        /** @var \App\Models\Sucursal $sucursal */
        $sucursal = $negocio->sucursales()->whereKey($resolvedSucursalId)->firstOrFail();

        $ordenes = $negocio->ordenes()
            ->with($this->ordenRelations())
            ->where('sucursal_id', $resolvedSucursalId)
            ->whereIn('status', [
                Orden::STATUS_PAGADA,
                Orden::STATUS_EN_COCINA,
                Orden::STATUS_LISTA,
            ])
            ->latest('id')
            ->get();

        $nuevo = [];
        $enPreparacion = [];
        $listo = [];

        foreach ($ordenes as $orden) {
            $bucket = $this->kitchenBucketForOrden($orden);
            if ($bucket === 'nuevo') {
                $nuevo[] = $orden;
            } elseif ($bucket === 'en_preparacion') {
                $enPreparacion[] = $orden;
            } elseif ($bucket === 'listo') {
                $listo[] = $orden;
            }
        }

        return [
            'sucursal' => [
                'id' => $sucursal->id,
                'type' => $sucursal->type,
                'name' => $sucursal->name,
            ],
            'nuevo' => $nuevo,
            'en_preparacion' => $enPreparacion,
            'listo' => $listo,
        ];
    }

    /**
     * Resuelve sucursal para listados/cocina.
     * Staff → siempre su sucursal. Maestro → la seleccionada (obligatoria en cocina).
     */
    public function resolveSucursalForQuery(
        Negocio $negocio,
        User|Staff $actor,
        ?int $sucursalId,
        bool $requireForMaestro = false,
    ): ?int {
        if ($actor instanceof Staff) {
            $staffSucursalId = (int) $actor->sucursal_id;
            if ($staffSucursalId <= 0) {
                throw new HttpException(422, 'Tu usuario staff no tiene sucursal asignada.');
            }

            if ($sucursalId !== null && $sucursalId !== $staffSucursalId) {
                throw new HttpException(403, 'No puedes consultar pedidos de otra sucursal.');
            }

            return $staffSucursalId;
        }

        if ($sucursalId === null || $sucursalId <= 0) {
            if ($requireForMaestro) {
                throw new HttpException(
                    422,
                    'Selecciona una sucursal para ver los pedidos de cocina.',
                );
            }

            return null;
        }

        if (! $negocio->sucursales()->whereKey($sucursalId)->exists()) {
            throw new HttpException(422, 'La sucursal no pertenece a tu negocio.');
        }

        return $sucursalId;
    }

    /**
     * Columna KDS según estatus de orden / detalles.
     */
    private function kitchenBucketForOrden(Orden $orden): ?string
    {
        if ((int) $orden->status === Orden::STATUS_LISTA) {
            return 'listo';
        }

        if ((int) $orden->status === Orden::STATUS_EN_COCINA) {
            return 'en_preparacion';
        }

        if ((int) $orden->status === Orden::STATUS_PAGADA) {
            $detalles = $orden->relationLoaded('detalles')
                ? $orden->detalles
                : $orden->detalles()->get();

            $active = $detalles->whereNotIn('status', [
                OrdenDetalle::STATUS_CANCELADO,
                OrdenDetalle::STATUS_ENTREGADO,
            ]);

            if ($active->isEmpty()) {
                return 'listo';
            }

            if ($active->every(fn (OrdenDetalle $d) => (int) $d->status === OrdenDetalle::STATUS_LISTO)) {
                return 'listo';
            }

            if ($active->contains(fn (OrdenDetalle $d) => (int) $d->status === OrdenDetalle::STATUS_EN_PREPARACION)) {
                return 'en_preparacion';
            }

            return 'nuevo';
        }

        return null;
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

        $previous = (int) $orden->status;
        $orden->status = $status;
        $orden->updated_by = $this->auditUserId($actor, $orden->negocio);
        $this->applyOrdenKitchenProgress($orden, $actor, $previous, $status);
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
        $this->syncOrdenKitchenFromDetalle($orden, $actor, $status);
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

    /**
     * Timestamps + duraciones de cocina a nivel orden.
     * Nuevo → En preparación → Listo (también si se salta un paso).
     */
    private function applyOrdenKitchenProgress(
        Orden $orden,
        User|Staff $actor,
        int $previous,
        int $status,
    ): void {
        $now = now();

        if ($status === Orden::STATUS_EN_COCINA) {
            $this->markOrdenEntrandoPreparacion($orden, $actor, $now);
        }

        if ($status === Orden::STATUS_LISTA) {
            $this->markOrdenListo($orden, $actor, $now);
        }

        if ($status === Orden::STATUS_ENTREGADA) {
            $orden->finished_at = $orden->finished_at ?? $now;
            if ($actor instanceof Staff && ! $orden->finished_by_staff_id) {
                $orden->finished_by_staff_id = $actor->id;
            }
            // Si llegó a entregada sin pasar por LISTA, cierra tiempos de cocina.
            if ($orden->listo_at === null && in_array($previous, [
                Orden::STATUS_PAGADA,
                Orden::STATUS_EN_COCINA,
            ], true)) {
                $this->markOrdenListo($orden, $actor, $now);
            }
        }
    }

    private function applyDetalleStaffTracking(OrdenDetalle $detalle, User|Staff $actor, int $status): void
    {
        if (! $actor instanceof Staff) {
            return;
        }

        $now = now();

        if (in_array($status, OrdenDetalle::ADVANCE_STATUSES, true)) {
            if (! $detalle->advanced_by_staff_id) {
                $detalle->advanced_by_staff_id = $actor->id;
            }
            $detalle->advanced_at = $detalle->advanced_at ?? $now;
        }

        if (in_array($status, OrdenDetalle::FINISH_STATUSES, true)) {
            if (! $detalle->advanced_by_staff_id) {
                $detalle->advanced_by_staff_id = $actor->id;
                $detalle->advanced_at = $detalle->advanced_at ?? $now;
            }
            if (! $detalle->finished_by_staff_id) {
                $detalle->finished_by_staff_id = $actor->id;
            }
            $detalle->finished_at = $detalle->finished_at ?? $now;
        }
    }

    private function syncOrdenKitchenFromDetalle(Orden $orden, User|Staff $actor, int $detalleStatus): void
    {
        if ($detalleStatus === OrdenDetalle::STATUS_EN_PREPARACION) {
            $previous = (int) $orden->status;
            if ($previous === Orden::STATUS_PAGADA || $orden->preparacion_started_at === null) {
                $orden->status = Orden::STATUS_EN_COCINA;
                $this->applyOrdenKitchenProgress($orden, $actor, $previous, Orden::STATUS_EN_COCINA);
            }

            return;
        }

        if (! in_array($detalleStatus, [
            OrdenDetalle::STATUS_LISTO,
            OrdenDetalle::STATUS_ENTREGADO,
        ], true)) {
            return;
        }

        // Primer producto listo sin haber entrado a preparación a nivel orden.
        if ($orden->preparacion_started_at === null
            && (int) $orden->status === Orden::STATUS_PAGADA) {
            $previous = (int) $orden->status;
            $orden->status = Orden::STATUS_EN_COCINA;
            $this->applyOrdenKitchenProgress($orden, $actor, $previous, Orden::STATUS_EN_COCINA);
        }

        $pending = $orden->detalles()
            ->whereNotIn('status', [
                OrdenDetalle::STATUS_LISTO,
                OrdenDetalle::STATUS_ENTREGADO,
                OrdenDetalle::STATUS_CANCELADO,
            ])
            ->exists();

        if (! $pending && (int) $orden->status !== Orden::STATUS_LISTA
            && (int) $orden->status !== Orden::STATUS_ENTREGADA
            && (int) $orden->status !== Orden::STATUS_CANCELADA) {
            $previous = (int) $orden->status;
            $orden->status = Orden::STATUS_LISTA;
            $this->applyOrdenKitchenProgress($orden, $actor, $previous, Orden::STATUS_LISTA);
        }
    }

    private function markOrdenEntrandoPreparacion(Orden $orden, User|Staff $actor, mixed $now): void
    {
        if ($orden->preparacion_started_at === null) {
            $orden->preparacion_started_at = $now;
            $orden->advanced_at = $orden->advanced_at ?? $now;
            if ($orden->seconds_in_nuevo === null) {
                $orden->seconds_in_nuevo = $this->secondsBetween($orden->created_at, $now);
            }
        }

        if ($actor instanceof Staff && ! $orden->advanced_by_staff_id) {
            $orden->advanced_by_staff_id = $actor->id;
        }
    }

    private function markOrdenListo(Orden $orden, User|Staff $actor, mixed $now): void
    {
        if ($orden->listo_at !== null) {
            return;
        }

        if ($orden->preparacion_started_at === null) {
            $orden->preparacion_started_at = $now;
            $orden->advanced_at = $orden->advanced_at ?? $now;
            if ($orden->seconds_in_nuevo === null) {
                $orden->seconds_in_nuevo = $this->secondsBetween($orden->created_at, $now);
            }
            $orden->seconds_in_preparacion = 0;
        } elseif ($orden->seconds_in_preparacion === null) {
            $orden->seconds_in_preparacion = $this->secondsBetween($orden->preparacion_started_at, $now);
        }

        $orden->listo_at = $now;
        $orden->finished_at = $orden->finished_at ?? $now;
        $orden->seconds_total_listo = $this->secondsBetween($orden->created_at, $now);

        if ($actor instanceof Staff) {
            if (! $orden->advanced_by_staff_id) {
                $orden->advanced_by_staff_id = $actor->id;
            }
            if (! $orden->finished_by_staff_id) {
                $orden->finished_by_staff_id = $actor->id;
            }
        }
    }

    private function secondsBetween(mixed $from, mixed $to): int
    {
        if ($from === null || $to === null) {
            return 0;
        }

        $start = $from instanceof \DateTimeInterface
            ? $from->getTimestamp()
            : strtotime((string) $from);
        $end = $to instanceof \DateTimeInterface
            ? $to->getTimestamp()
            : strtotime((string) $to);

        if ($start === false || $end === false) {
            return 0;
        }

        return max(0, $end - $start);
    }

    /**
     * Correlativo por sucursal: cada sucursal inicia en 1 (#000001),
     * independiente de otras sucursales o negocios.
     */
    private function nextOrderNumber(Negocio $negocio, int $sucursalId): int
    {
        $last = $negocio->ordenes()
            ->where('sucursal_id', $sucursalId)
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
