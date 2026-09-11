<?php

namespace App\Services;

use App\Models\CuentaPorCobrar;
use App\Models\Negocio;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\Producto;
use App\Models\Staff;
use App\Models\Sucursal;
use App\Models\TipoVenta;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrdenService
{
    use ResolvesNegocioFromActor;

    private const STAFF_WITH = 'id,negocio_id,username,sucursal_id,empleado_id,status';

    public function __construct(
        private readonly TurnoCajaService $turnosCaja,
    ) {}

    /**
     * Crea orden + detalles.
     *
     * - POS "Cobrar": requiere payment_type/pagos, marca pagada y registra venta.
     * - Orden en mesa: status pendiente, sin pago ni venta; el cobro es posterior.
     *
     * @param  array{
     *     customer_name: string,
     *     sucursal_id?: int|null,
     *     payment_type?: string|null,
     *     pagos?: list<array{payment_type: string, amount: float|int|string}>|null,
     *     status?: int,
     *     orden_en_mesa?: bool,
     *     pendiente_pago?: bool,
     *     seconds_in_caja?: int|null,
     *     detalles: list<array{
     *         producto_id: int,
     *         tipo_venta_id?: int|null,
     *         empleado_id?: int|null,
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

        $isPendientePago = $this->wantsPendientePago($data);
        $status = $isPendientePago
            ? Orden::STATUS_PENDIENTE
            : (int) ($data['status'] ?? Orden::STATUS_PAGADA);

        if (! in_array($status, Orden::STATUSES, true)) {
            throw new HttpException(422, 'Estatus de orden inválido.');
        }

        if ($isPendientePago) {
            $this->assertCanOrdenEnMesa($actor);
        }

        return DB::transaction(function () use ($negocio, $actor, $data, $sucursalId, $status, $isPendientePago) {
            $turno = $isPendientePago
                ? $this->turnosCaja->requireOpenTurnoForSucursal($negocio, $sucursalId)
                : $this->turnosCaja->requireOpenTurnoForSale($negocio, $actor, $sucursalId);

            $auditId = $this->auditUserId($actor, $negocio);
            $orderNumber = $this->nextOrderNumber($negocio, $sucursalId);
            $lineRows = $this->buildDetalleRows($negocio, $data['detalles'], $sucursalId);

            // Solo líneas NO diferidas suman al total cobrado hoy (caja).
            $total = round((float) collect($lineRows)
                ->reject(fn (array $row) => (bool) ($row['diferido'] ?? false))
                ->sum(fn (array $row) => (float) $row['quantity'] * (float) $row['price']), 2);

            $pagos = $isPendientePago ? [] : $this->resolvePagosForCreate($data, $total);
            $paymentType = $isPendientePago
                ? null
                : (count($pagos) === 1
                    ? $pagos[0]['payment_type']
                    : Orden::PAYMENT_TYPE_MIXTO);

            $orden = $negocio->ordenes()->create([
                'order_number' => $orderNumber,
                'sucursal_id' => $sucursalId,
                'customer_name' => $data['customer_name'],
                'payment_type' => $paymentType,
                'total' => $total,
                'status' => $status,
                'seconds_in_caja' => $data['seconds_in_caja'] ?? null,
                'created_by_staff_id' => $actor instanceof Staff ? $actor->id : null,
                'created_by' => $auditId,
                'updated_by' => $auditId,
            ]);

            foreach ($pagos as $pago) {
                $orden->pagos()->create([
                    'payment_type' => $pago['payment_type'],
                    'amount' => $pago['amount'],
                ]);
            }

            $this->persistDetalles($negocio, $orden, $lineRows, $turno, $sucursalId);

            // Solo cobros con monto real de caja generan venta (excluye diferidos del total).
            if (! $isPendientePago && $total > 0 && in_array($status, [
                Orden::STATUS_PAGADA,
                Orden::STATUS_EN_COCINA,
                Orden::STATUS_LISTA,
                Orden::STATUS_ENTREGADA,
            ], true)) {
                $this->turnosCaja->registerVentaFromOrden($turno, $orden->load('pagos'), $actor);
            }

            return $orden->load($this->ordenRelations());
        });
    }

    /**
     * Agrega productos a una orden pendiente de pago (orden en mesa).
     *
     * @param  array{detalles: list<array<string, mixed>>}  $data
     */
    public function addDetalles(Orden $orden, User|Staff $actor, array $data): Orden
    {
        $this->assertCanOperateOrden($actor, $orden);
        $this->assertCanOrdenEnMesa($actor);

        if ((int) $orden->status === Orden::STATUS_CANCELADA) {
            throw new HttpException(422, 'No puedes agregar productos a una orden cancelada.');
        }

        if (! $orden->isPendientePago()) {
            throw new HttpException(422, 'Solo puedes agregar productos a una orden pendiente de pago.');
        }

        $detalles = $data['detalles'] ?? [];

        return DB::transaction(function () use ($orden, $actor, $detalles) {
            $negocio = $orden->negocio;
            $sucursalId = (int) $orden->sucursal_id;
            $turno = $this->turnosCaja->requireOpenTurnoForSucursal($negocio, $sucursalId);
            $lineRows = $this->buildDetalleRows($negocio, $detalles, $sucursalId);

            $this->persistDetalles($negocio, $orden, $lineRows, $turno, $sucursalId);

            $orden->updated_by = $this->auditUserId($actor, $negocio);
            $this->recalcOrdenTotal($orden);
            $orden->save();

            return $orden->refresh()->load($this->ordenRelations());
        });
    }

    /**
     * Cobra una orden en mesa (cuando el cliente solicita la cuenta).
     * Registra pagos + venta del turno; no crea columnas nuevas.
     *
     * @param  array{
     *     payment_type?: string|null,
     *     pagos?: list<array{payment_type: string, amount: float|int|string}>|null,
     *     seconds_in_caja?: int|null
     * }  $data
     */
    public function cobrar(Orden $orden, User|Staff $actor, array $data): Orden
    {
        $this->assertCanOperateOrden($actor, $orden);

        if ((int) $orden->status === Orden::STATUS_CANCELADA) {
            throw new HttpException(422, 'No puedes cobrar una orden cancelada.');
        }

        if (! $orden->isPendientePago()) {
            throw new HttpException(422, 'Esta orden ya tiene un pago registrado.');
        }

        return DB::transaction(function () use ($orden, $actor, $data) {
            $negocio = $orden->negocio;
            $this->recalcOrdenTotal($orden);
            $total = round((float) $orden->total, 2);
            $pagos = $this->resolvePagosForCreate($data, $total);
            $paymentType = count($pagos) === 1
                ? $pagos[0]['payment_type']
                : Orden::PAYMENT_TYPE_MIXTO;

            $turno = $this->turnosCaja->requireOpenTurnoForSale(
                $negocio,
                $actor,
                (int) $orden->sucursal_id,
            );

            foreach ($pagos as $pago) {
                $orden->pagos()->create([
                    'payment_type' => $pago['payment_type'],
                    'amount' => $pago['amount'],
                ]);
            }

            $orden->payment_type = $paymentType;
            if ((int) $orden->status === Orden::STATUS_PENDIENTE) {
                $orden->status = Orden::STATUS_PAGADA;
            }
            if (array_key_exists('seconds_in_caja', $data)) {
                $orden->seconds_in_caja = $data['seconds_in_caja'];
            }
            $orden->updated_by = $this->auditUserId($actor, $negocio);
            $orden->save();

            if ($total > 0) {
                $this->turnosCaja->registerVentaFromOrden($turno, $orden->load('pagos'), $actor);
            }

            return $orden->refresh()->load($this->ordenRelations());
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
            ->with($this->ordenRelations())
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
     *     nuevo: list<Orden>,
     *     en_preparacion: list<Orden>,
     *     listo: list<Orden>
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

        /** @var Sucursal $sucursal */
        $sucursal = $negocio->sucursales()->whereKey($resolvedSucursalId)->firstOrFail();

        $ordenes = $negocio->ordenes()
            ->with($this->ordenRelations())
            ->where('sucursal_id', $resolvedSucursalId)
            ->whereIn('status', [
                Orden::STATUS_PENDIENTE,
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
     * Órdenes del día (hoy) de la sucursal: todas + buckets Nuevo / En preparación / Listo.
     *
     * @return array{
     *     fecha: string,
     *     sucursal: array{id: int, type: string, name: string},
     *     ordenes: list<Orden>,
     *     nuevo: list<Orden>,
     *     en_preparacion: list<Orden>,
     *     listo: list<Orden>
     * }
     */
    public function listHoy(Negocio $negocio, User|Staff $actor, ?int $sucursalId = null): array
    {
        $resolvedSucursalId = $this->resolveSucursalForQuery(
            $negocio,
            $actor,
            $sucursalId,
            requireForMaestro: true,
        );

        /** @var Sucursal $sucursal */
        $sucursal = $negocio->sucursales()->whereKey($resolvedSucursalId)->firstOrFail();

        $ordenes = $negocio->ordenes()
            ->with($this->ordenRelations())
            ->where('sucursal_id', $resolvedSucursalId)
            ->whereDate('created_at', now()->toDateString())
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
            'fecha' => now()->toDateString(),
            'sucursal' => [
                'id' => $sucursal->id,
                'type' => $sucursal->type,
                'name' => $sucursal->name,
            ],
            'ordenes' => $ordenes->all(),
            'nuevo' => $nuevo,
            'en_preparacion' => $enPreparacion,
            'listo' => $listo,
        ];
    }

    /**
     * Cancela uno o más productos de la orden (status = 5).
     * El price del detalle pasa a negativo y se recalcula orden.total
     * sumando solo líneas no canceladas y no diferidas.
     *
     * @param  list<int>  $detalleIds
     */
    public function cancelarDetalles(Orden $orden, User|Staff $actor, array $detalleIds): Orden
    {
        $ids = array_values(array_unique(array_map('intval', $detalleIds)));
        if ($ids === []) {
            throw new HttpException(422, 'Debes enviar al menos un detalle para cancelar.');
        }

        if ((int) $orden->status === Orden::STATUS_CANCELADA) {
            throw new HttpException(422, 'La orden ya está cancelada.');
        }

        return DB::transaction(function () use ($orden, $actor, $ids) {
            $detalles = $orden->detalles()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($detalles->count() !== count($ids)) {
                throw new HttpException(422, 'Uno o más detalles no pertenecen a esta orden.');
            }

            foreach ($detalles as $detalle) {
                $this->aplicarCancelacionDetalle($detalle);
            }

            $orden->updated_by = $this->auditUserId($actor, $orden->negocio);
            $this->recalcOrdenTotal($orden);
            $this->syncOrdenKitchenFromDetalle($orden, $actor, OrdenDetalle::STATUS_CANCELADO);
            $orden->save();

            // Ajusta/elimina la venta del turno para que el corte no cuente montos cancelados.
            $this->turnosCaja->syncVentaFromOrden($orden->refresh());

            return $orden->refresh()->load($this->ordenRelations());
        });
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
    public function kitchenBucketForOrden(Orden $orden): ?string
    {
        if ((int) $orden->status === Orden::STATUS_LISTA) {
            return 'listo';
        }

        if ((int) $orden->status === Orden::STATUS_EN_COCINA) {
            return 'en_preparacion';
        }

        if (in_array((int) $orden->status, [Orden::STATUS_PENDIENTE, Orden::STATUS_PAGADA], true)) {
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

        if ($status === OrdenDetalle::STATUS_CANCELADO) {
            $this->cancelarDetalles($orden, $actor, [$detalleId]);

            return $orden->detalles()
                ->whereKey($detalleId)
                ->with([
                    'producto:id,negocio_id,name,price',
                    'advancedByStaff:'.self::STAFF_WITH,
                    'finishedByStaff:'.self::STAFF_WITH,
                ])
                ->firstOrFail();
        }

        $detalle = $orden->detalles()->whereKey($detalleId)->firstOrFail();

        if ((int) $detalle->status === OrdenDetalle::STATUS_CANCELADO) {
            throw new HttpException(422, 'No puedes cambiar el estatus de un producto cancelado.');
        }

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
     * Marca un único detalle como entregado / sin entregar.
     * No toca precios, totales ni el estatus de la orden.
     */
    public function setDetalleEntrega(
        Orden $orden,
        int $detalleId,
        User|Staff $actor,
        int $statusEntregado,
    ): OrdenDetalle {
        if (! in_array($statusEntregado, OrdenDetalle::ENTREGA_STATUSES, true)) {
            throw new HttpException(422, 'Estatus de entrega inválido.');
        }

        /** @var OrdenDetalle $detalle */
        $detalle = $orden->detalles()->whereKey($detalleId)->firstOrFail();

        if ((int) $detalle->status === OrdenDetalle::STATUS_CANCELADO) {
            throw new HttpException(422, 'No puedes marcar la entrega de un producto cancelado.');
        }

        $detalle->status_entregado = $statusEntregado;
        $detalle->save();

        $orden->updated_by = $this->auditUserId($actor, $orden->negocio);
        $orden->save();

        return $detalle->refresh()->load([
            'producto:id,negocio_id,name,price',
            'advancedByStaff:'.self::STAFF_WITH,
            'finishedByStaff:'.self::STAFF_WITH,
        ]);
    }

    private function aplicarCancelacionDetalle(OrdenDetalle $detalle): void
    {
        if ((int) $detalle->status === OrdenDetalle::STATUS_CANCELADO) {
            throw new HttpException(
                422,
                "El detalle #{$detalle->id} ({$detalle->product_name}) ya está cancelado.",
            );
        }

        $unitPrice = abs((float) $detalle->price);
        $detalle->price = round(-$unitPrice, 2);
        $detalle->status = OrdenDetalle::STATUS_CANCELADO;
        $detalle->save();
    }

    /**
     * Total cobrable: suma quantity * price de líneas no canceladas y no diferidas.
     * Los cancelados quedan con price negativo para historial, pero no entran al total.
     */
    private function recalcOrdenTotal(Orden $orden): void
    {
        $total = (float) $orden->detalles()
            ->where('status', '!=', OrdenDetalle::STATUS_CANCELADO)
            ->where(function ($q) {
                $q->where('diferido', false)->orWhereNull('diferido');
            })
            ->get()
            ->sum(fn (OrdenDetalle $d) => (float) $d->quantity * (float) $d->price);

        $orden->total = round(max(0, $total), 2);
    }

    /**
     * @return list<string>
     */
    public function ordenRelations(): array
    {
        return [
            'sucursal:id,negocio_id,type,name',
            'pagos',
            'detalles.producto:id,negocio_id,name,price',
            // No listar require_autori aquí: si la columna aún no existe en prod, el cobro rompe con 500.
            'detalles.tipoVenta:id,negocio_id,name,tipo_descuento,valor_descuento,diferir_cobro,requiere_empleado,status',
            'detalles.empleado:id,negocio_id,first_name,paternal_surname,maternal_surname',
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
     * Normaliza pagos del request. Si solo viene payment_type, crea un pago con el total.
     * La suma de montos debe coincidir con el total cobrable de la orden.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{payment_type: string, amount: float}>
     */
    private function resolvePagosForCreate(array $data, float $total): array
    {
        $rawPagos = $data['pagos'] ?? null;

        if (! is_array($rawPagos) || $rawPagos === []) {
            $singleType = $data['payment_type'] ?? null;
            if (! is_string($singleType) || trim($singleType) === '') {
                throw new HttpException(422, 'Envía tipo_pago o el arreglo pagos.');
            }

            if ($total <= 0) {
                return [[
                    'payment_type' => $this->normalizePaymentType($singleType),
                    'amount' => 0.0,
                ]];
            }

            return [[
                'payment_type' => $this->normalizePaymentType($singleType),
                'amount' => $total,
            ]];
        }

        $pagos = [];
        foreach ($rawPagos as $pago) {
            if (! is_array($pago)) {
                continue;
            }

            $type = $pago['payment_type'] ?? $pago['tipo_pago'] ?? null;
            if (! is_string($type) || trim($type) === '') {
                throw new HttpException(422, 'Cada pago debe incluir tipo_pago.');
            }

            $amount = round((float) ($pago['amount'] ?? $pago['monto'] ?? 0), 2);
            if ($amount <= 0) {
                throw new HttpException(422, 'Cada monto de pago debe ser mayor a cero.');
            }

            $pagos[] = [
                'payment_type' => $this->normalizePaymentType($type),
                'amount' => $amount,
            ];
        }

        if ($pagos === []) {
            throw new HttpException(422, 'Debes enviar al menos una forma de pago.');
        }

        $suma = round(array_sum(array_column($pagos, 'amount')), 2);
        if (abs($suma - $total) > 0.009) {
            throw new HttpException(
                422,
                "La suma de los pagos ({$suma}) debe ser igual al total de la orden ({$total}).",
            );
        }

        return $pagos;
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
                Orden::STATUS_PENDIENTE,
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
            if (in_array($previous, [Orden::STATUS_PENDIENTE, Orden::STATUS_PAGADA], true)
                || $orden->preparacion_started_at === null) {
                $orden->status = Orden::STATUS_EN_COCINA;
                $this->applyOrdenKitchenProgress($orden, $actor, $previous, Orden::STATUS_EN_COCINA);
            }

            return;
        }

        if (! in_array($detalleStatus, [
            OrdenDetalle::STATUS_LISTO,
            OrdenDetalle::STATUS_ENTREGADO,
            OrdenDetalle::STATUS_CANCELADO,
        ], true)) {
            return;
        }

        // Primer producto listo sin haber entrado a preparación a nivel orden.
        if ($detalleStatus !== OrdenDetalle::STATUS_CANCELADO
            && $orden->preparacion_started_at === null
            && in_array((int) $orden->status, [Orden::STATUS_PENDIENTE, Orden::STATUS_PAGADA], true)) {
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
            $hasActive = $orden->detalles()
                ->where('status', '!=', OrdenDetalle::STATUS_CANCELADO)
                ->exists();

            // Si cancelaron todos los productos, no marcar lista; solo recalcular kitchen.
            if (! $hasActive) {
                return;
            }

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

        if ($normalized === 'card' || $normalized === 'credit') {
            $normalized = 'tarjeta';
        }

        if (! in_array($normalized, Orden::PAYMENT_TYPES, true)) {
            throw new HttpException(422, 'Tipo de pago inválido. Usa: efectivo, tarjeta, transferencia o credito.');
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $detalles
     * @return list<array<string, mixed>>
     */
    private function buildDetalleRows(Negocio $negocio, array $detalles, int $sucursalId): array
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

            $stockInactivo = $negocio->stockProductos()
                ->where('sucursal_id', $sucursalId)
                ->where('producto_id', $producto->id)
                ->where('is_active', false)
                ->exists();

            if ($stockInactivo) {
                throw new HttpException(
                    422,
                    "El producto {$producto->name} no está disponible en esta sucursal.",
                );
            }

            $quantity = (float) $item['quantity'];
            if ($quantity <= 0) {
                throw new HttpException(422, 'La cantidad debe ser mayor a cero.');
            }

            $precioLista = round((float) $producto->price, 2);
            $tipoVentaId = isset($item['tipo_venta_id']) && $item['tipo_venta_id'] !== null
                ? (int) $item['tipo_venta_id']
                : null;

            $tipoVenta = null;
            $diferido = false;

            if ($tipoVentaId !== null) {
                /** @var TipoVenta|null $tipoVenta */
                $tipoVenta = $negocio->tiposVenta()
                    ->whereKey($tipoVentaId)
                    ->where('status', true)
                    ->first();

                if (! $tipoVenta) {
                    throw new HttpException(
                        422,
                        "El tipo de venta {$tipoVentaId} no pertenece a tu negocio o está inactivo.",
                    );
                }

                // Fuente de verdad: descuento calculado en backend (ignora price del front).
                $price = round($tipoVenta->applyDiscount($precioLista), 2);
                $diferido = (bool) $tipoVenta->diferir_cobro;
            } else {
                $price = array_key_exists('price', $item) && $item['price'] !== null
                    ? round((float) $item['price'], 2)
                    : $precioLista;
            }

            $empleadoId = isset($item['empleado_id']) && $item['empleado_id'] !== null
                ? (int) $item['empleado_id']
                : null;

            $requiresEmpleado = $diferido || (bool) ($tipoVenta?->requiere_empleado);
            if ($requiresEmpleado && $empleadoId === null) {
                throw new HttpException(
                    422,
                    'Debes seleccionar un empleado para este tipo de venta.',
                );
            }

            // Si el front manda empleado_id (aunque el tipo no lo exija), se guarda y se expone.
            if ($empleadoId !== null && ! $negocio->empleados()->whereKey($empleadoId)->exists()) {
                throw new HttpException(422, "El empleado {$empleadoId} no pertenece a tu negocio.");
            }

            $detailStatus = (int) ($item['status'] ?? OrdenDetalle::STATUS_PENDIENTE);
            if (! in_array($detailStatus, OrdenDetalle::STATUSES, true)) {
                throw new HttpException(422, 'Estatus de detalle inválido.');
            }

            $rows[] = [
                'producto_id' => $producto->id,
                'tipo_venta_id' => $tipoVentaId,
                'product_name' => $item['product_name'] ?? $producto->name,
                'quantity' => $quantity,
                'precio_lista' => $precioLista,
                'diferido' => $diferido,
                'empleado_id' => $empleadoId,
                'price' => $price,
                'extras' => $item['extras'] ?? null,
                'notes' => $item['notes'] ?? null,
                'status' => $detailStatus,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $lineRows
     */
    private function persistDetalles(
        Negocio $negocio,
        Orden $orden,
        array $lineRows,
        TurnoCaja $turno,
        int $sucursalId,
    ): void {
        $now = now();

        foreach ($lineRows as $row) {
            $detalle = $orden->detalles()->create($row);

            if (! (bool) ($row['diferido'] ?? false)) {
                continue;
            }

            $lineMonto = round((float) $row['quantity'] * (float) $row['price'], 2);

            $negocio->cuentasPorCobrar()->create([
                'sucursal_id' => $sucursalId,
                'empleado_id' => $row['empleado_id'],
                'orden_id' => $orden->id,
                'orden_detalle_id' => $detalle->id,
                'turno_caja_id' => $turno->id,
                'concepto' => $detalle->product_name,
                'monto' => $lineMonto,
                'status' => CuentaPorCobrar::STATUS_PENDIENTE,
                'fecha_generado' => $now,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function wantsPendientePago(array $data): bool
    {
        if (! empty($data['orden_en_mesa']) || ! empty($data['pendiente_pago'])) {
            return true;
        }

        if ((int) ($data['status'] ?? 0) === Orden::STATUS_PENDIENTE) {
            return true;
        }

        $hasPaymentType = filled($data['payment_type'] ?? null);
        $pagos = $data['pagos'] ?? null;
        $hasPagos = is_array($pagos) && $pagos !== [];

        return ! $hasPaymentType && ! $hasPagos;
    }

    private function assertCanOrdenEnMesa(User|Staff $actor): void
    {
        if ($actor instanceof User) {
            return;
        }

        $actor->loadMissing('role');

        if (! $actor->role?->allows('ordenEnMesa')) {
            throw new HttpException(403, 'No tienes permiso para registrar órdenes en mesa.');
        }
    }

    private function assertCanOperateOrden(User|Staff $actor, Orden $orden): void
    {
        if ($actor instanceof Staff && (int) $actor->sucursal_id !== (int) $orden->sucursal_id) {
            throw new HttpException(403, 'No puedes operar órdenes de otra sucursal.');
        }
    }
}
