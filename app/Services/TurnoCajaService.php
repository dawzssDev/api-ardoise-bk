<?php

namespace App\Services;

use App\Models\DepositoEnTurno;
use App\Models\GastoEnTurno;
use App\Models\Negocio;
use App\Models\Orden;
use App\Models\Proveedor;
use App\Models\Staff;
use App\Models\TurnoCaja;
use App\Models\TurnoCajaCorte;
use App\Models\User;
use App\Models\Venta;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TurnoCajaService
{
    use ResolvesNegocioFromActor;

    public function __construct(
        private readonly MaeCuentaContaSucDetalleService $cuentasContablesDetalles,
    ) {}

    /**
     * @param  array{sucursal_id?: int|null, fondo_inicial?: float|int|string|null, status_gerencia?: mixed}  $data
     */
    public function abrir(Negocio $negocio, User|Staff $actor, array $data): TurnoCaja
    {
        $sucursalId = $this->resolveSucursalId($negocio, $actor, $data['sucursal_id'] ?? null);
        $this->assertSucursalBelongs($negocio, $sucursalId);

        if ($this->openTurnoForActor($negocio, $actor, $sucursalId)) {
            throw new HttpException(422, 'Ya tienes un turno de caja abierto en esta sucursal. Ciérralo antes de abrir otro.');
        }

        $this->assertNoPendingAdminClose($negocio, $sucursalId);

        $fondo = round((float) ($data['fondo_inicial'] ?? 0), 2);
        if ($fondo < 0) {
            throw new HttpException(422, 'El fondo inicial no puede ser negativo.');
        }

        return TurnoCaja::query()->create([
            'id_user' => $actor instanceof Staff ? $actor->id : null,
            'user_id' => $actor instanceof User ? $actor->id : $this->auditUserId($actor, $negocio),
            'negocio_id' => $negocio->id,
            'sucursal_id' => $sucursalId,
            'fondo_inicial' => $fondo,
            'total_pagos_proveedores' => 0,
            'total_gastos_operativos' => 0,
            'total_retiros_efectivo' => 0,
            'total_depositos_efectivo' => 0,
            'status' => TurnoCaja::STATUS_ABIERTO,
            'status_administrador' => TurnoCaja::STATUS_ABIERTO,
            'status_gerencia' => $this->statusGerenciaFromData($data, TurnoCaja::STATUS_ABIERTO),
            'fecha_apertura' => now(),
        ])->load($this->turnoRelations());
    }

    public function cerrar(
        TurnoCaja $turno,
        User|Staff $actor,
        float $efectivoReal,
        ?string $observaciones = null,
        ?float $efectivoRealCajera = null,
        mixed $statusGerencia = null,
        bool $hasStatusGerencia = false,
    ): TurnoCaja {
        if (! $turno->isAdminOpen()) {
            $this->assertCanCerrarGerencia($actor, $turno);

            return $this->cerrarPorGerencia(
                $turno,
                $actor,
                $hasStatusGerencia ? $statusGerencia : TurnoCaja::STATUS_CERRADO,
            );
        }

        $this->assertCanCorteCaja($actor);
        $this->assertCanManageTurno($turno, $actor);

        if ($efectivoReal < 0) {
            throw new HttpException(422, 'El efectivo real no puede ser negativo.');
        }

        if ($efectivoRealCajera !== null && $efectivoRealCajera < 0) {
            throw new HttpException(422, 'El efectivo real de la cajera no puede ser negativo.');
        }

        if ($this->isCajeraClose($actor)) {
            return $this->cerrarPorCajera(
                $turno,
                $actor,
                $efectivoReal,
                $observaciones,
                $efectivoRealCajera,
                $statusGerencia,
                $hasStatusGerencia,
            );
        }

        return $this->cerrarPorAdministrador(
            $turno,
            $actor,
            $efectivoReal,
            $observaciones,
            $efectivoRealCajera,
            $statusGerencia,
            $hasStatusGerencia,
        );
    }

    public function listForNegocio(
        Negocio $negocio,
        User|Staff $actor,
        int $perPage = 15,
        ?int $sucursalId = null,
        ?string $status = null,
    ): LengthAwarePaginator {
        $query = TurnoCaja::query()
            ->where('negocio_id', $negocio->id)
            ->with($this->turnoRelations())
            ->latest('id');

        if ($actor instanceof Staff && ! $this->staffCanListAllTurnos($actor)) {
            $query->where('id_user', $actor->id);
        }

        if ($sucursalId) {
            $this->assertSucursalBelongs($negocio, $sucursalId);
            $query->where('sucursal_id', $sucursalId);
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    /**
     * Turnos con status_administrador = abierto (pendientes de cierre admin).
     *
     * @return list<TurnoCaja>
     */
    public function listPendientesAdministrador(
        Negocio $negocio,
        User|Staff $actor,
        ?int $sucursalId = null,
    ): array {
        $query = TurnoCaja::query()
            ->where('negocio_id', $negocio->id)
            ->where('status_administrador', TurnoCaja::STATUS_ABIERTO)
            ->with($this->turnoRelations())
            ->latest('id');

        if ($sucursalId) {
            $this->assertSucursalBelongs($negocio, $sucursalId);
            $query->where('sucursal_id', $sucursalId);
        }

        // Cajera solo ve los suyos; admin/gerencia/dueño ve todos los pendientes de la sucursal.
        if ($actor instanceof Staff && ! $this->staffCanListAllTurnos($actor)) {
            $query->where('id_user', $actor->id);
        }

        return $query->get()->all();
    }

    public function findForNegocio(Negocio $negocio, int $turnoId): TurnoCaja
    {
        return TurnoCaja::query()
            ->where('negocio_id', $negocio->id)
            ->with($this->turnoRelations())
            ->findOrFail($turnoId);
    }

    public function openTurnoForActor(Negocio $negocio, User|Staff $actor, ?int $sucursalId = null): ?TurnoCaja
    {
        $query = TurnoCaja::query()
            ->where('negocio_id', $negocio->id)
            ->where('status', TurnoCaja::STATUS_ABIERTO)
            ->with($this->turnoRelations())
            ->latest('id');

        if ($actor instanceof Staff) {
            $query->where('id_user', $actor->id);
        } else {
            $query->where('user_id', $actor->id)->whereNull('id_user');
        }

        if ($sucursalId) {
            $query->where('sucursal_id', $sucursalId);
        }

        return $query->first();
    }

    /**
     * Turno abierto obligatorio para cobrar (POS).
     */
    public function requireOpenTurnoForSale(
        Negocio $negocio,
        User|Staff $actor,
        int $sucursalId,
    ): TurnoCaja {
        $turno = $this->openTurnoForActor($negocio, $actor, $sucursalId);

        if (! $turno) {
            throw new HttpException(
                422,
                'Debes iniciar turno de caja antes de realizar ventas.',
            );
        }

        return $turno;
    }

    /**
     * Registra una fila en tb_ventas por cada forma de pago de la orden.
     * Así el corte reparte efectivo/tarjeta/transferencia correctamente.
     *
     * @return list<Venta>
     */
    public function registerVentaFromOrden(TurnoCaja $turno, Orden $orden, User|Staff $actor): array
    {
        $orden->loadMissing('pagos');
        $cajeraId = $actor instanceof Staff ? $actor->id : $turno->id_user;
        $fecha = $orden->created_at ?? now();

        $pagos = $orden->pagos;
        if ($pagos->isEmpty()) {
            return [
                Venta::query()->create([
                    'turno_caja_id' => $turno->id,
                    'id_user' => $cajeraId,
                    'orden_id' => $orden->id,
                    'order_number' => $orden->order_number,
                    'payment_type' => $orden->payment_type,
                    'total' => $orden->total,
                    'sucursal_id' => $orden->sucursal_id,
                    'negocio_id' => $orden->negocio_id,
                    'fecha_venta' => $fecha,
                ]),
            ];
        }

        $ventas = [];
        foreach ($pagos as $pago) {
            $monto = round((float) $pago->amount, 2);
            if ($monto <= 0) {
                continue;
            }

            $ventas[] = Venta::query()->create([
                'turno_caja_id' => $turno->id,
                'id_user' => $cajeraId,
                'orden_id' => $orden->id,
                'order_number' => $orden->order_number,
                'payment_type' => $pago->payment_type,
                'total' => $monto,
                'sucursal_id' => $orden->sucursal_id,
                'negocio_id' => $orden->negocio_id,
                'fecha_venta' => $fecha,
            ]);
        }

        return $ventas;
    }

    /**
     * Sincroniza ventas del turno con el total actual de la orden
     * (p. ej. tras cancelar productos). Si el total queda en 0, elimina las ventas.
     * Con cobro combinado, reparte el nuevo total proporcionalmente entre métodos.
     *
     * @return list<Venta>
     */
    public function syncVentaFromOrden(Orden $orden): array
    {
        $orden->loadMissing('pagos');
        $ventas = Venta::query()->where('orden_id', $orden->id)->orderBy('id')->get();
        if ($ventas->isEmpty()) {
            return [];
        }

        $total = round((float) $orden->total, 2);
        if ($total <= 0) {
            Venta::query()->where('orden_id', $orden->id)->delete();
            $orden->pagos()->update(['amount' => 0]);

            return [];
        }

        $pagos = $orden->pagos;
        if ($pagos->isEmpty()) {
            /** @var Venta $venta */
            $venta = $ventas->first();
            $venta->total = $total;
            $venta->payment_type = $orden->payment_type;
            $venta->save();

            // Elimina ventas huérfanas si alguna vez hubo split.
            Venta::query()
                ->where('orden_id', $orden->id)
                ->where('id', '!=', $venta->id)
                ->delete();

            return [$venta->refresh()];
        }

        $pagosSum = round((float) $pagos->sum(fn ($p) => (float) $p->amount), 2);
        if ($pagosSum <= 0) {
            Venta::query()->where('orden_id', $orden->id)->delete();

            return [];
        }

        // Escala montos de pagos al nuevo total (cancelaciones parciales).
        $scaled = [];
        $assigned = 0.0;
        $lastIndex = $pagos->count() - 1;
        foreach ($pagos->values() as $index => $pago) {
            if ($index === $lastIndex) {
                $monto = round($total - $assigned, 2);
            } else {
                $monto = round(((float) $pago->amount / $pagosSum) * $total, 2);
                $assigned = round($assigned + $monto, 2);
            }
            $pago->amount = max(0, $monto);
            $pago->save();
            $scaled[] = $pago;
        }

        // Recrea ventas 1:1 con los pagos (evita filas huérfanas).
        Venta::query()->where('orden_id', $orden->id)->delete();

        $base = $ventas->first();
        $synced = [];
        foreach ($scaled as $pago) {
            $monto = round((float) $pago->amount, 2);
            if ($monto <= 0) {
                continue;
            }

            $synced[] = Venta::query()->create([
                'turno_caja_id' => $base->turno_caja_id,
                'id_user' => $base->id_user,
                'orden_id' => $orden->id,
                'order_number' => $orden->order_number,
                'payment_type' => $pago->payment_type,
                'total' => $monto,
                'sucursal_id' => $orden->sucursal_id,
                'negocio_id' => $orden->negocio_id,
                'fecha_venta' => $base->fecha_venta ?? $orden->created_at ?? now(),
            ]);
        }

        return $synced;
    }

    public function listVentas(TurnoCaja $turno, int $perPage = 50): LengthAwarePaginator
    {
        $this->syncTurnoVentasWithOrdenes($turno);

        return $turno->ventas()
            ->where('tb_ventas.total', '>', 0)
            ->with(['cajera:id,username,sucursal_id', 'orden:id,order_number,customer_name,status,total'])
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{tipo_gasto: string, descripcion: string, monto: float|int|string, proveedor_id?: int|null}  $data
     */
    public function registrarGasto(TurnoCaja $turno, User|Staff $actor, array $data): GastoEnTurno
    {
        $this->assertCanRegisterGasto($turno, $actor);

        if (! $turno->isOpen()) {
            throw new HttpException(422, 'No puedes registrar gastos en un turno cerrado.');
        }

        $tipo = GastoEnTurno::normalizeTipo($data['tipo_gasto'] ?? null);
        if ($tipo === null) {
            throw new HttpException(422, 'El tipo de gasto no es válido.');
        }

        $monto = round((float) $data['monto'], 2);
        if ($monto <= 0) {
            throw new HttpException(422, 'El monto debe ser mayor a cero.');
        }

        $proveedorId = $this->resolveProveedorIdForGasto($turno, $tipo, $data['proveedor_id'] ?? null);

        return DB::transaction(function () use ($turno, $actor, $data, $tipo, $monto, $proveedorId) {
            $gasto = GastoEnTurno::query()->create([
                'turno_caja_id' => $turno->id,
                'id_user' => $actor instanceof Staff ? $actor->id : $turno->id_user,
                'user_id' => $actor instanceof User ? $actor->id : $this->auditUserId($actor, $turno->negocio),
                'negocio_id' => $turno->negocio_id,
                'sucursal_id' => $turno->sucursal_id,
                'tipo_gasto' => $tipo,
                'proveedor_id' => $proveedorId,
                'descripcion' => trim((string) $data['descripcion']),
                'monto' => $monto,
                'fecha_registro' => now(),
            ]);

            $this->syncGastoTotals($turno);

            return $gasto->refresh();
        });
    }

    private function resolveProveedorIdForGasto(TurnoCaja $turno, string $tipo, mixed $proveedorId): ?int
    {
        if ($tipo !== GastoEnTurno::TIPO_PAGO_PROVEEDOR) {
            return null;
        }

        $id = (int) $proveedorId;
        if ($id < 1) {
            throw new HttpException(422, 'El proveedor es obligatorio cuando el gasto es un pago a proveedor.');
        }

        $proveedor = Proveedor::query()
            ->where('negocio_id', $turno->negocio_id)
            ->whereKey($id)
            ->first();

        if (! $proveedor) {
            throw new HttpException(422, 'El proveedor no existe o no pertenece a tu negocio.');
        }

        if (! $proveedor->isActivo()) {
            throw new HttpException(422, 'No puedes registrar un pago a un proveedor dado de baja.');
        }

        return $proveedor->id;
    }

    public function listGastos(TurnoCaja $turno, int $perPage = 50): LengthAwarePaginator
    {
        return $turno->gastos()
            ->with([
                'cajero:id,username,sucursal_id',
                'user:id,name,email',
                'sucursal:id,negocio_id,type,name',
                'proveedor:id,negocio_id,name,legal_name,rfc,status',
            ])
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * @return array{pago_proveedor: float, gasto_operativo: float, retiro_efectivo: float, total: float}
     */
    public function sumGastosByTipo(TurnoCaja $turno): array
    {
        $rows = $turno->gastos()
            ->selectRaw('tipo_gasto, SUM(monto) as suma')
            ->groupBy('tipo_gasto')
            ->pluck('suma', 'tipo_gasto');

        $pagoProveedor = round((float) ($rows[GastoEnTurno::TIPO_PAGO_PROVEEDOR] ?? 0), 2);
        $gastoOperativo = round((float) ($rows[GastoEnTurno::TIPO_GASTO_OPERATIVO] ?? 0), 2);
        $retiroEfectivo = round((float) ($rows[GastoEnTurno::TIPO_RETIRO_EFECTIVO] ?? 0), 2);

        return [
            'pago_proveedor' => $pagoProveedor,
            'gasto_operativo' => $gastoOperativo,
            'retiro_efectivo' => $retiroEfectivo,
            'total' => round($pagoProveedor + $gastoOperativo + $retiroEfectivo, 2),
        ];
    }

    /**
     * @param  array{descripcion: string, monto: float|int|string}  $data
     */
    public function registrarDeposito(TurnoCaja $turno, User|Staff $actor, array $data): DepositoEnTurno
    {
        $this->assertCanRegisterDeposito($turno, $actor);

        if (! $turno->isOpen()) {
            throw new HttpException(422, 'No puedes registrar depósitos en un turno cerrado.');
        }

        $monto = round((float) $data['monto'], 2);
        if ($monto <= 0) {
            throw new HttpException(422, 'El monto debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($turno, $actor, $data, $monto) {
            $deposito = DepositoEnTurno::query()->create([
                'turno_caja_id' => $turno->id,
                'id_user' => $actor instanceof Staff ? $actor->id : $turno->id_user,
                'user_id' => $actor instanceof User ? $actor->id : $this->auditUserId($actor, $turno->negocio),
                'negocio_id' => $turno->negocio_id,
                'sucursal_id' => $turno->sucursal_id,
                'descripcion' => trim((string) $data['descripcion']),
                'monto' => $monto,
                'fecha_registro' => now(),
            ]);

            $this->syncDepositoTotals($turno);

            return $deposito->refresh();
        });
    }

    public function listDepositos(TurnoCaja $turno, int $perPage = 50): LengthAwarePaginator
    {
        return $turno->depositos()
            ->with([
                'cajero:id,username,sucursal_id',
                'user:id,name,email',
                'sucursal:id,negocio_id,type,name',
            ])
            ->latest('id')
            ->paginate($perPage);
    }

    public function sumDepositos(TurnoCaja $turno): float
    {
        return round((float) $turno->depositos()->sum('monto'), 2);
    }

    /**
     * @return array{efectivo: float, tarjeta: float, transferencia: float, total: float}
     */
    public function sumVentasByPayment(TurnoCaja $turno): array
    {
        // Suma tb_ventas.total (ya sincronizado). Con cobro combinado hay
        // varias filas por orden; NO usar ordenes.total o se duplicaría el monto.
        $rows = $turno->ventas()
            ->selectRaw('payment_type, SUM(total) as suma')
            ->groupBy('payment_type')
            ->pluck('suma', 'payment_type');

        $efectivo = round((float) ($rows['efectivo'] ?? 0), 2);
        $transferencia = round((float) ($rows['transferencia'] ?? 0), 2);
        // tarjeta + credito (compatibilidad con órdenes antiguas)
        $tarjeta = round(
            (float) ($rows['tarjeta'] ?? 0) + (float) ($rows['credito'] ?? 0),
            2
        );
        $total = round($efectivo + $tarjeta + $transferencia, 2);

        return [
            'efectivo' => $efectivo,
            'tarjeta' => $tarjeta,
            'transferencia' => $transferencia,
            'total' => $total,
        ];
    }

    public function previewCierre(TurnoCaja $turno): array
    {
        // Corrige ventas desfasadas (órdenes con productos cancelados).
        $this->syncTurnoVentasWithOrdenes($turno);

        $movimientos = $this->totalesMovimientos($turno);

        return [
            ...$movimientos,
            'efectivo_esperado' => $movimientos['total_ventas_efectivo'],
            'fondo_inicial' => (float) $turno->fondo_inicial,
            'status' => $turno->status,
            'status_administrador' => $turno->status_administrador,
            'status_gerencia' => $turno->status_gerencia,
            'tramo_actual' => $this->totalesTramoActual($turno),
            'cortes_acumulados' => $this->sumCortes($turno),
        ];
    }

    /**
     * Alinea tb_ventas.total con ordenes.total / orden_pagos (elimina ventas a $0).
     */
    private function syncTurnoVentasWithOrdenes(TurnoCaja $turno): void
    {
        $ordenIds = $turno->ventas()
            ->whereNotNull('orden_id')
            ->distinct()
            ->pluck('orden_id');

        if ($ordenIds->isEmpty()) {
            return;
        }

        Orden::query()
            ->whereIn('id', $ordenIds)
            ->with('pagos')
            ->get()
            ->each(fn (Orden $orden) => $this->syncVentaFromOrden($orden));
    }

    /**
     * Corte parcial (tipo_corte = 1). El turno sigue abierto.
     */
    public function registrarCorteParcial(
        TurnoCaja $turno,
        User|Staff $actor,
        float $efectivoRealCajera,
        ?string $observaciones = null,
    ): TurnoCajaCorte {
        $this->assertCanCorteCaja($actor);
        $this->assertCanManageTurno($turno, $actor);

        if (! $turno->isOpen()) {
            throw new HttpException(422, 'No puedes registrar un corte parcial en un turno cerrado.');
        }

        if ($this->hasCorteCierre($turno)) {
            throw new HttpException(422, 'Este turno ya tiene un corte de cierre.');
        }

        if ($efectivoRealCajera < 0) {
            throw new HttpException(422, 'El efectivo real de la cajera no puede ser negativo.');
        }

        return DB::transaction(function () use ($turno, $actor, $efectivoRealCajera, $observaciones) {
            return $this->createCorte(
                $turno,
                $actor,
                TurnoCajaCorte::TIPO_PARCIAL,
                $efectivoRealCajera,
                $observaciones,
            );
        });
    }

    /**
     * @return array{
     *     cortes: list<TurnoCajaCorte>,
     *     tramo_actual: array<string, float>,
     *     acumulado: array<string, float>
     * }
     */
    public function listCortes(TurnoCaja $turno): array
    {
        return [
            'cortes' => $turno->cortes()
                ->with($this->corteRelations())
                ->orderBy('id')
                ->get()
                ->all(),
            'tramo_actual' => $this->totalesTramoActual($turno),
            'acumulado' => $this->sumCortes($turno),
        ];
    }

    /**
     * Cierre de cajera: status → cerrado; status_administrador sigue abierto.
     */
    private function cerrarPorCajera(
        TurnoCaja $turno,
        User|Staff $actor,
        float $efectivoReal,
        ?string $observaciones,
        ?float $efectivoRealCajera,
        mixed $statusGerencia = null,
        bool $hasStatusGerencia = false,
    ): TurnoCaja {
        if (! $turno->isOpen()) {
            throw new HttpException(422, 'Este turno de caja ya está cerrado por la cajera.');
        }

        $efectivoRealCajera ??= $efectivoReal;

        return DB::transaction(function () use ($turno, $actor, $observaciones, $efectivoRealCajera, $statusGerencia, $hasStatusGerencia) {
            $this->ensureCorteCierre($turno, $actor, $efectivoRealCajera, $observaciones);
            $cortes = $this->sumCortes($turno);
            $live = $this->sumVentasByPayment($turno);

            $payload = [
                ...$this->turnoTotalsFromCortes($cortes),
                'efectivo_esperado' => $live['efectivo'],
                'efectivo_real_cajera' => $cortes['efectivo_real_cajera'],
                'fecha_cierre_cajera' => now(),
                'status' => TurnoCaja::STATUS_CERRADO,
                // El administrador aún debe cerrar el corte.
                'status_administrador' => TurnoCaja::STATUS_ABIERTO,
                'observaciones_cierre' => $observaciones,
            ];

            if ($hasStatusGerencia) {
                $payload['status_gerencia'] = $this->statusGerenciaFromData(
                    ['status_gerencia' => $statusGerencia],
                    (string) $turno->status_gerencia,
                );
            }

            $turno->fill($payload);
            $turno->save();

            return $turno->refresh()->load($this->turnoRelations());
        });
    }

    /**
     * Cierre de administrador: cierra status_administrador (y status si aún estaba abierto).
     */
    private function cerrarPorAdministrador(
        TurnoCaja $turno,
        User|Staff $actor,
        float $efectivoReal,
        ?string $observaciones,
        ?float $efectivoRealCajera,
        mixed $statusGerencia = null,
        bool $hasStatusGerencia = false,
    ): TurnoCaja {
        if (! $turno->isAdminOpen()) {
            throw new HttpException(422, 'Este corte de caja ya fue cerrado por el administrador.');
        }

        return DB::transaction(function () use ($turno, $actor, $efectivoReal, $observaciones, $efectivoRealCajera, $statusGerencia, $hasStatusGerencia) {
            $this->ensureCorteCierre(
                $turno,
                $actor,
                $efectivoRealCajera ?? $efectivoReal,
                $observaciones,
            );

            $cortes = $this->sumCortes($turno);
            $totales = $this->sumVentasByPayment($turno);
            $gastos = $this->sumGastosByTipo($turno);
            $depositos = $this->sumDepositos($turno);

            $efectivoEsperado = $totales['efectivo'];
            $pagosProveedores = $gastos['pago_proveedor'];
            $gastosOperativos = $gastos['gasto_operativo'];
            $retirosEfectivo = $gastos['retiro_efectivo'];

            $diferencia = round(
                $efectivoEsperado + $depositos - $efectivoReal - $pagosProveedores - $gastosOperativos - $retirosEfectivo,
                2
            );

            $payload = [
                ...$this->turnoTotalsFromCortes($cortes),
                'efectivo_esperado' => $efectivoEsperado,
                'efectivo_real' => round($efectivoReal, 2),
                'efectivo_real_cajera' => $cortes['efectivo_real_cajera'],
                'diferencia' => $diferencia,
                'status' => TurnoCaja::STATUS_CERRADO,
                'status_administrador' => TurnoCaja::STATUS_CERRADO,
                'fecha_cierre' => now(),
                'fecha_cierre_cajera' => $turno->fecha_cierre_cajera ?? now(),
                'observaciones_cierre' => $observaciones ?? $turno->observaciones_cierre,
            ];

            if ($hasStatusGerencia) {
                $payload['status_gerencia'] = $this->statusGerenciaFromData(
                    ['status_gerencia' => $statusGerencia],
                    (string) $turno->status_gerencia,
                );
            }

            $turno->fill($payload);
            $turno->save();

            return $turno->refresh()->load($this->turnoRelations());
        });
    }

    /**
     * Cierre de gerencia: status_gerencia + abona ventas efectivo/tarjeta a cuenta matriz.
     * No toca status_administrador ni recalcula totales del corte.
     */
    private function cerrarPorGerencia(TurnoCaja $turno, User|Staff $actor, mixed $statusGerencia): TurnoCaja
    {
        if (! $turno->isGerenciaOpen()) {
            throw new HttpException(422, 'La validación gerencial ya fue cerrada.');
        }

        return DB::transaction(function () use ($turno, $actor, $statusGerencia) {
            $turno->status_gerencia = $this->statusGerenciaFromData(
                ['status_gerencia' => $statusGerencia],
                TurnoCaja::STATUS_CERRADO,
            );
            $turno->save();

            $this->cuentasContablesDetalles->registrarVentasCorteGerencia($turno->refresh(), $actor);

            return $turno->refresh()->load($this->turnoRelations());
        });
    }

    private function statusGerenciaFromData(array $data, string $default): string
    {
        if (! array_key_exists('status_gerencia', $data)) {
            return $default;
        }

        $value = $data['status_gerencia'];
        if ($value === null || $value === '') {
            return $default;
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * @return array{
     *     total_ventas_efectivo: float,
     *     total_ventas_tarjeta: float,
     *     total_ventas_transferencia: float,
     *     total_ventas: float,
     *     total_pagos_proveedores: float,
     *     total_gastos_operativos: float,
     *     total_retiros_efectivo: float,
     *     total_depositos_efectivo: float
     * }
     */
    private function totalesMovimientos(TurnoCaja $turno): array
    {
        $totales = $this->sumVentasByPayment($turno);
        $gastos = $this->sumGastosByTipo($turno);
        $depositos = $this->sumDepositos($turno);

        return [
            'total_ventas_efectivo' => $totales['efectivo'],
            'total_ventas_tarjeta' => $totales['tarjeta'],
            'total_ventas_transferencia' => $totales['transferencia'],
            'total_ventas' => $totales['total'],
            'total_pagos_proveedores' => $gastos['pago_proveedor'],
            'total_gastos_operativos' => $gastos['gasto_operativo'],
            'total_retiros_efectivo' => $gastos['retiro_efectivo'],
            'total_depositos_efectivo' => $depositos,
        ];
    }

    /**
     * Totales del tramo actual: movimientos vivos menos cortes ya guardados.
     *
     * @return array{
     *     total_ventas_efectivo: float,
     *     total_ventas_tarjeta: float,
     *     total_ventas_transferencia: float,
     *     total_ventas: float,
     *     total_pagos_proveedores: float,
     *     total_gastos_operativos: float,
     *     total_retiros_efectivo: float,
     *     total_depositos_efectivo: float
     * }
     */
    private function totalesTramoActual(TurnoCaja $turno): array
    {
        $live = $this->totalesMovimientos($turno);
        $prev = $this->sumCortes($turno);

        return [
            'total_ventas_efectivo' => $this->diffMoney($live['total_ventas_efectivo'], $prev['total_ventas_efectivo']),
            'total_ventas_tarjeta' => $this->diffMoney($live['total_ventas_tarjeta'], $prev['total_ventas_tarjeta']),
            'total_ventas_transferencia' => $this->diffMoney($live['total_ventas_transferencia'], $prev['total_ventas_transferencia']),
            'total_ventas' => $this->diffMoney($live['total_ventas'], $prev['total_ventas']),
            'total_pagos_proveedores' => $this->diffMoney($live['total_pagos_proveedores'], $prev['total_pagos_proveedores']),
            'total_gastos_operativos' => $this->diffMoney($live['total_gastos_operativos'], $prev['total_gastos_operativos']),
            'total_retiros_efectivo' => $this->diffMoney($live['total_retiros_efectivo'], $prev['total_retiros_efectivo']),
            'total_depositos_efectivo' => $this->diffMoney($live['total_depositos_efectivo'], $prev['total_depositos_efectivo']),
        ];
    }

    /**
     * @return array{
     *     total_ventas_efectivo: float,
     *     total_ventas_tarjeta: float,
     *     total_ventas_transferencia: float,
     *     total_ventas: float,
     *     total_pagos_proveedores: float,
     *     total_gastos_operativos: float,
     *     total_retiros_efectivo: float,
     *     total_depositos_efectivo: float,
     *     efectivo_real_cajera: float
     * }
     */
    private function sumCortes(TurnoCaja $turno): array
    {
        $row = $turno->cortes()
            ->selectRaw('
                COALESCE(SUM(total_ventas_efectivo), 0) as total_ventas_efectivo,
                COALESCE(SUM(total_ventas_tarjeta), 0) as total_ventas_tarjeta,
                COALESCE(SUM(total_ventas_transferencia), 0) as total_ventas_transferencia,
                COALESCE(SUM(total_ventas), 0) as total_ventas,
                COALESCE(SUM(total_pagos_proveedores), 0) as total_pagos_proveedores,
                COALESCE(SUM(total_gastos_operativos), 0) as total_gastos_operativos,
                COALESCE(SUM(total_retiros_efectivo), 0) as total_retiros_efectivo,
                COALESCE(SUM(total_depositos_efectivo), 0) as total_depositos_efectivo,
                COALESCE(SUM(efectivo_real_cajera), 0) as efectivo_real_cajera
            ')
            ->first();

        return [
            'total_ventas_efectivo' => round((float) ($row?->total_ventas_efectivo ?? 0), 2),
            'total_ventas_tarjeta' => round((float) ($row?->total_ventas_tarjeta ?? 0), 2),
            'total_ventas_transferencia' => round((float) ($row?->total_ventas_transferencia ?? 0), 2),
            'total_ventas' => round((float) ($row?->total_ventas ?? 0), 2),
            'total_pagos_proveedores' => round((float) ($row?->total_pagos_proveedores ?? 0), 2),
            'total_gastos_operativos' => round((float) ($row?->total_gastos_operativos ?? 0), 2),
            'total_retiros_efectivo' => round((float) ($row?->total_retiros_efectivo ?? 0), 2),
            'total_depositos_efectivo' => round((float) ($row?->total_depositos_efectivo ?? 0), 2),
            'efectivo_real_cajera' => round((float) ($row?->efectivo_real_cajera ?? 0), 2),
        ];
    }

    /**
     * @param  array<string, float>  $cortes
     * @return array<string, float>
     */
    private function turnoTotalsFromCortes(array $cortes): array
    {
        return [
            'total_ventas_efectivo' => $cortes['total_ventas_efectivo'],
            'total_ventas_tarjeta' => $cortes['total_ventas_tarjeta'],
            'total_ventas_transferencia' => $cortes['total_ventas_transferencia'],
            'total_ventas' => $cortes['total_ventas'],
            'total_pagos_proveedores' => $cortes['total_pagos_proveedores'],
            'total_gastos_operativos' => $cortes['total_gastos_operativos'],
            'total_retiros_efectivo' => $cortes['total_retiros_efectivo'],
            'total_depositos_efectivo' => $cortes['total_depositos_efectivo'],
        ];
    }

    private function diffMoney(float $live, float $prev): float
    {
        return round(max(0, $live - $prev), 2);
    }

    private function hasCorteCierre(TurnoCaja $turno): bool
    {
        return $turno->cortes()
            ->where('tipo_corte', TurnoCajaCorte::TIPO_CIERRE)
            ->exists();
    }

    private function ensureCorteCierre(
        TurnoCaja $turno,
        User|Staff $actor,
        float $efectivoRealCajera,
        ?string $observaciones,
    ): void {
        if ($this->hasCorteCierre($turno)) {
            return;
        }

        $this->createCorte(
            $turno,
            $actor,
            TurnoCajaCorte::TIPO_CIERRE,
            $efectivoRealCajera,
            $observaciones,
        );
    }

    private function createCorte(
        TurnoCaja $turno,
        User|Staff $actor,
        int $tipoCorte,
        float $efectivoRealCajera,
        ?string $observaciones,
    ): TurnoCajaCorte {
        $turno->loadMissing('negocio');
        $tramo = $this->totalesTramoActual($turno);

        return TurnoCajaCorte::query()->create([
            'turno_caja_id' => $turno->id,
            'id_user' => $actor instanceof Staff ? $actor->id : $turno->id_user,
            'user_id' => $actor instanceof User ? $actor->id : $this->auditUserId($actor, $turno->negocio),
            'negocio_id' => $turno->negocio_id,
            'sucursal_id' => $turno->sucursal_id,
            'total_ventas_efectivo' => $tramo['total_ventas_efectivo'],
            'total_ventas_tarjeta' => $tramo['total_ventas_tarjeta'],
            'total_ventas_transferencia' => $tramo['total_ventas_transferencia'],
            'total_ventas' => $tramo['total_ventas'],
            'total_pagos_proveedores' => $tramo['total_pagos_proveedores'],
            'total_gastos_operativos' => $tramo['total_gastos_operativos'],
            'total_retiros_efectivo' => $tramo['total_retiros_efectivo'],
            'total_depositos_efectivo' => $tramo['total_depositos_efectivo'],
            'efectivo_real_cajera' => round($efectivoRealCajera, 2),
            'tipo_corte' => $tipoCorte,
            'fecha_cierre_cajera' => now(),
            'observaciones_cierre' => $observaciones,
        ])->load($this->corteRelations());
    }

    /**
     * @return list<string>
     */
    private function corteRelations(): array
    {
        return [
            'cajera:id,negocio_id,username,sucursal_id,empleado_id,status',
            'user:id,name,email',
            'sucursal:id,negocio_id,type,name',
        ];
    }

    private function syncGastoTotals(TurnoCaja $turno): void
    {
        $gastos = $this->sumGastosByTipo($turno);

        $turno->forceFill([
            'total_pagos_proveedores' => $gastos['pago_proveedor'],
            'total_gastos_operativos' => $gastos['gasto_operativo'],
            'total_retiros_efectivo' => $gastos['retiro_efectivo'],
        ])->save();
    }

    private function syncDepositoTotals(TurnoCaja $turno): void
    {
        $turno->forceFill([
            'total_depositos_efectivo' => $this->sumDepositos($turno),
        ])->save();
    }

    private function assertCanRegisterDeposito(TurnoCaja $turno, User|Staff $actor): void
    {
        if ($actor instanceof Staff) {
            $actor->loadMissing('role');

            if ($actor->role?->allows('corteCaja')) {
                if ((int) $turno->negocio_id !== (int) $actor->negocio_id) {
                    throw new HttpException(403, 'No puedes registrar depósitos en este turno de caja.');
                }

                return;
            }

            if ((int) $turno->id_user !== (int) $actor->id) {
                throw new HttpException(403, 'No puedes registrar depósitos en el turno de otra cajera.');
            }

            return;
        }

        if ((int) $turno->negocio_id !== (int) ($actor->negocio?->id)) {
            throw new HttpException(403, 'No puedes registrar depósitos en este turno de caja.');
        }
    }

    private function assertCanRegisterGasto(TurnoCaja $turno, User|Staff $actor): void
    {
        if ($actor instanceof Staff) {
            $actor->loadMissing('role');

            if ($actor->role?->allows('corteCaja')) {
                if ((int) $turno->negocio_id !== (int) $actor->negocio_id) {
                    throw new HttpException(403, 'No puedes registrar gastos en este turno de caja.');
                }

                return;
            }

            if ((int) $turno->id_user !== (int) $actor->id) {
                throw new HttpException(403, 'No puedes registrar gastos en el turno de otra cajera.');
            }

            return;
        }

        if ((int) $turno->negocio_id !== (int) ($actor->negocio?->id)) {
            throw new HttpException(403, 'No puedes registrar gastos en este turno de caja.');
        }
    }

    /**
     * No se puede abrir turno si hay un corte pendiente de cierre del administrador.
     */
    private function assertNoPendingAdminClose(Negocio $negocio, int $sucursalId): void
    {
        $pendiente = TurnoCaja::query()
            ->where('negocio_id', $negocio->id)
            ->where('sucursal_id', $sucursalId)
            ->where('status_administrador', TurnoCaja::STATUS_ABIERTO)
            ->latest('id')
            ->first();

        if (! $pendiente) {
            return;
        }

        throw new HttpException(
            422,
            'Existe un corte abierto para el administrador. No se puede abrir turno hasta que se cierre ese corte.',
        );
    }

    /**
     * Dueño / corteCaja → cierre administrador.
     * Solo corteCajaCajera → cierre cajera.
     */
    private function isCajeraClose(User|Staff $actor): bool
    {
        if ($actor instanceof User) {
            return false;
        }

        return ! $this->staffHasCorteAdmin($actor)
            && (bool) $actor->role?->allows('corteCajaCajera');
    }

    private function staffHasCorteAdmin(Staff $actor): bool
    {
        $actor->loadMissing('role');

        return (bool) $actor->role?->allows('corteCaja');
    }

    /**
     * Puede ver todos los turnos de la sucursal (no solo el propio):
     * corteCaja (admin) o corteCajaGerenteAdmo (gerencia).
     */
    private function staffCanListAllTurnos(Staff $actor): bool
    {
        $actor->loadMissing('role');

        return (bool) $actor->role?->allows('corteCaja')
            || (bool) $actor->role?->allows('corteCajaGerenteAdmo');
    }

    /**
     * Dueño del negocio siempre puede.
     * Staff requiere corteCaja (admin) o corteCajaCajera (cajera).
     */
    private function assertCanCorteCaja(User|Staff $actor): void
    {
        if ($actor instanceof User) {
            return;
        }

        $actor->loadMissing('role');

        $canCorte = (bool) $actor->role?->allows('corteCaja')
            || (bool) $actor->role?->allows('corteCajaCajera')
            || (bool) $actor->role?->allows('corteCajaGerenteAdmo');

        if (! $canCorte) {
            throw new HttpException(403, 'No tienes permiso para realizar el corte de caja.');
        }
    }

    private function assertCanCerrarGerencia(User|Staff $actor, TurnoCaja $turno): void
    {
        if ($actor instanceof User) {
            if ((int) $turno->negocio_id !== (int) ($actor->negocio?->id)) {
                throw new HttpException(403, 'No puedes gestionar este turno de caja.');
            }

            return;
        }

        $actor->loadMissing('role');
        $canGerencia = (bool) $actor->role?->allows('corteCajaGerenteAdmo')
            || (bool) $actor->role?->allows('corteCaja');

        if (! $canGerencia) {
            throw new HttpException(403, 'No tienes permiso para cerrar la validación gerencial.');
        }

        if ((int) $turno->negocio_id !== (int) $actor->negocio_id) {
            throw new HttpException(403, 'No puedes gestionar este turno de caja.');
        }
    }

    private function assertCanManageTurno(TurnoCaja $turno, User|Staff $actor): void
    {
        if ($actor instanceof Staff) {
            $actor->loadMissing('role');

            // Staff con corteCaja (admin) o corteCajaGerenteAdmo puede gestionar cortes de su negocio.
            if ($actor->role?->allows('corteCaja') || $actor->role?->allows('corteCajaGerenteAdmo')) {
                if ((int) $turno->negocio_id !== (int) $actor->negocio_id) {
                    throw new HttpException(403, 'No puedes gestionar este turno de caja.');
                }

                return;
            }

            // Cajera solo su propio turno.
            if ((int) $turno->id_user !== (int) $actor->id) {
                throw new HttpException(403, 'No puedes cerrar el turno de otra cajera.');
            }

            return;
        }

        if ((int) $turno->negocio_id !== (int) ($actor->negocio?->id)) {
            throw new HttpException(403, 'No puedes gestionar este turno de caja.');
        }
    }

    private function resolveSucursalId(Negocio $negocio, User|Staff $actor, mixed $sucursalId): int
    {
        if ($sucursalId !== null && $sucursalId !== '') {
            return (int) $sucursalId;
        }

        if ($actor instanceof Staff) {
            return (int) $actor->sucursal_id;
        }

        throw new HttpException(422, 'La sucursal es obligatoria para abrir caja.');
    }

    private function assertSucursalBelongs(Negocio $negocio, int $sucursalId): void
    {
        if (! $negocio->sucursales()->whereKey($sucursalId)->exists()) {
            throw new HttpException(422, 'La sucursal no pertenece a tu negocio.');
        }
    }

    /**
     * @return list<string>
     */
    private function turnoRelations(): array
    {
        return [
            'cajera:id,negocio_id,username,sucursal_id,empleado_id,status',
            'user:id,name,email',
            'sucursal:id,negocio_id,type,name',
        ];
    }
}
