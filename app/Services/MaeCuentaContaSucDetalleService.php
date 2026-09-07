<?php

namespace App\Services;

use App\Models\MaeCuentaContaSuc;
use App\Models\MaeCuentaContaSucDetalle;
use App\Models\Negocio;
use App\Models\Staff;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MaeCuentaContaSucDetalleService
{
    use ResolvesNegocioFromActor;

    /**
     * @param  array{
     *     tipo_movimiento: string,
     *     descripcion_movimiento: string,
     *     monto_movimiento: float|int|string,
     *     cuenta_origen_id?: int|null,
     *     cuenta_destino_id?: int|null,
     *     status?: int
     * }  $data
     */
    public function create(
        MaeCuentaContaSuc $cuenta,
        User|Staff $actor,
        array $data,
    ): MaeCuentaContaSucDetalle {
        if ($cuenta->isDeleted()) {
            throw new HttpException(422, 'No puedes registrar movimientos en una cuenta contable eliminada.');
        }

        $tipo = $this->resolvedTipoMovimiento($data['tipo_movimiento'] ?? null);
        $monto = $this->resolvedMonto($data['monto_movimiento'] ?? null);

        $origenId = (int) ($data['cuenta_origen_id'] ?? $cuenta->id);
        $destinoId = (int) ($data['cuenta_destino_id'] ?? 0);
        $destinoEfectivo = $destinoId > 0 ? $destinoId : (int) $cuenta->id;
        $esGastoDirecto = MaeCuentaContaSucDetalle::isTipoGasto($tipo)
            || (
                $origenId > 0
                && $origenId === $destinoEfectivo
                && ! in_array($tipo, [
                    MaeCuentaContaSucDetalle::TIPO_DEPOSITO,
                    ...MaeCuentaContaSucDetalle::TIPOS_VENTA_CORTE,
                ], true)
            );

        if ($esGastoDirecto) {
            $tipoGasto = MaeCuentaContaSucDetalle::isTipoGasto($tipo)
                ? $tipo
                : ($tipo === MaeCuentaContaSucDetalle::TIPO_RETIRO
                    ? MaeCuentaContaSucDetalle::TIPO_RETIRO_EFECTIVO
                    : MaeCuentaContaSucDetalle::TIPO_GASTO_OPERATIVO);

            return $this->crearGastoDirecto($cuenta, $actor, $tipoGasto, $monto, $data);
        }

        [$origenId, $destinoId] = $this->resolvedCuentasMovimiento(
            $cuenta,
            $tipo,
            $data['cuenta_origen_id'] ?? null,
            $data['cuenta_destino_id'] ?? null,
        );

        $auditId = $this->auditUserId($actor, $cuenta->negocio);

        return DB::transaction(function () use ($cuenta, $tipo, $monto, $origenId, $destinoId, $data, $auditId) {
            $destino = $this->lockCuenta($destinoId);
            $origen = $origenId ? $this->lockCuenta($origenId) : null;
            $esVentaCorte = in_array($tipo, MaeCuentaContaSucDetalle::TIPOS_VENTA_CORTE, true);

            // transferencia/retiro sucursal → matriz: solicitud de retiro (saldo al autorizar)
            // Las ventas de corte NO entran aquí: solo abonan matriz.
            if (! $esVentaCorte && $this->esSolicitudSubcuentaAMaestra($origen, $destino)) {
                $tipo = MaeCuentaContaSucDetalle::TIPO_RETIRO;
                $this->assertSaldoSuficiente($origen, $monto);

                return $cuenta->detalles()->create([
                    'negocio_id' => $cuenta->negocio_id,
                    'tipo_movimiento' => $tipo,
                    'cuenta_origen_id' => $origenId,
                    'monto_movimiento' => $monto,
                    'cuenta_destino_id' => $destinoId,
                    'descripcion_movimiento' => trim((string) $data['descripcion_movimiento']),
                    'status' => MaeCuentaContaSucDetalle::STATUS_PENDIENTE,
                    'deleted' => MaeCuentaContaSucDetalle::DELETED_NO,
                    'created_by' => $auditId,
                    'updated_by' => $auditId,
                ]);
            }

            // transferencia matriz → sucursal: solicitud (descuenta maestra al crear)
            $pendiente = ! $esVentaCorte && $this->esSolicitudMaestraASubcuenta($origen, $destino);
            $status = $pendiente
                ? MaeCuentaContaSucDetalle::STATUS_PENDIENTE
                : MaeCuentaContaSucDetalle::STATUS_ACEPTADO;

            if ($pendiente) {
                $this->debit($origen, $monto);
            } else {
                $this->aplicarMovimientoInmediato($tipo, $origen, $destino, $monto);
            }

            return $cuenta->detalles()->create([
                'negocio_id' => $cuenta->negocio_id,
                'tipo_movimiento' => $tipo,
                'cuenta_origen_id' => $origenId,
                'monto_movimiento' => $monto,
                'cuenta_destino_id' => $destinoId,
                'descripcion_movimiento' => trim((string) $data['descripcion_movimiento']),
                'status' => $status,
                'deleted' => MaeCuentaContaSucDetalle::DELETED_NO,
                'created_by' => $auditId,
                'updated_by' => $auditId,
            ]);
        });
    }

    /**
     * Al autorizar corte gerencial: abona a cuenta matriz el efectivo_gerencia
     * y terminal_gerencia capturados por gerencia (no las ventas del POS).
     * Si hay venta con tarjeta, descuenta la comisión de terminal configurada en el negocio.
     *
     * @return list<MaeCuentaContaSucDetalle>
     */
    public function registrarVentasCorteGerencia(TurnoCaja $turno, User|Staff $actor): array
    {
        $turno->loadMissing(['sucursal:id,name', 'negocio:id,comision_venta_tarjeta']);

        $maestra = MaeCuentaContaSuc::query()
            ->where('negocio_id', $turno->negocio_id)
            ->where('tipo_cuenta', MaeCuentaContaSuc::TIPO_MAESTRA)
            ->where('deleted', MaeCuentaContaSuc::DELETED_NO)
            ->where('status', MaeCuentaContaSuc::STATUS_ACTIVO)
            ->orderBy('id')
            ->first();

        if (! $maestra) {
            throw new HttpException(422, 'No hay cuenta matriz activa para registrar el corte gerencial.');
        }

        $subcuenta = MaeCuentaContaSuc::query()
            ->where('negocio_id', $turno->negocio_id)
            ->where('tipo_cuenta', MaeCuentaContaSuc::TIPO_SUBCUENTA)
            ->where('sucursal_id', $turno->sucursal_id)
            ->where('deleted', MaeCuentaContaSuc::DELETED_NO)
            ->where('status', MaeCuentaContaSuc::STATUS_ACTIVO)
            ->orderBy('id')
            ->first();

        if (! $subcuenta) {
            throw new HttpException(
                422,
                'No hay cuenta contable de sucursal activa para registrar el origen del corte gerencial.',
            );
        }

        $efectivoGerencia = $turno->efectivo_gerencia;
        $terminalGerencia = $turno->terminal_gerencia;

        if ($efectivoGerencia === null || $terminalGerencia === null) {
            throw new HttpException(
                422,
                'Debes registrar la validación gerencial (efectivo_gerencia y terminal_gerencia) antes de cerrar el corte de gerencia.',
            );
        }

        $descripcion = $this->descripcionCorteGerencia($turno);
        $auditId = $this->auditUserId($actor, $turno->negocio);
        $creados = [];
        $montoTarjeta = round((float) $terminalGerencia, 2);
        $porcentajeComision = $this->porcentajeComisionVentaTarjeta($turno);
        $montoComision = $this->montoComisionTerminal($montoTarjeta, $porcentajeComision);
        $descripcionComision = $this->descripcionComisionTerminalCorteGerencia($turno, $porcentajeComision);

        $lineas = [
            [
                'tipo' => MaeCuentaContaSucDetalle::TIPO_VENTA_EFECTIVO,
                'monto' => round((float) $efectivoGerencia, 2),
            ],
            [
                'tipo' => MaeCuentaContaSucDetalle::TIPO_VENTA_TARJETA,
                'monto' => $montoTarjeta,
            ],
        ];

        return DB::transaction(function () use (
            $maestra,
            $subcuenta,
            $lineas,
            $descripcion,
            $auditId,
            $creados,
            $montoComision,
            $descripcionComision,
        ) {
            $destino = $this->lockCuenta($maestra->id);

            foreach ($lineas as $linea) {
                if ($linea['monto'] <= 0) {
                    continue;
                }

                $monto = number_format($linea['monto'], 2, '.', '');
                $this->credit($destino, $monto);
                $destino->refresh();

                $creados[] = $maestra->detalles()->create([
                    'negocio_id' => $maestra->negocio_id,
                    'tipo_movimiento' => $linea['tipo'],
                    'cuenta_origen_id' => $subcuenta->id,
                    'monto_movimiento' => $monto,
                    'cuenta_destino_id' => $maestra->id,
                    'descripcion_movimiento' => $descripcion,
                    'status' => MaeCuentaContaSucDetalle::STATUS_ACEPTADO,
                    'deleted' => MaeCuentaContaSucDetalle::DELETED_NO,
                    'created_by' => $auditId,
                    'updated_by' => $auditId,
                ]);
            }

            if ($montoComision > 0) {
                $monto = number_format($montoComision, 2, '.', '');
                $this->debit($destino, $monto);
                $destino->refresh();

                $creados[] = $maestra->detalles()->create([
                    'negocio_id' => $maestra->negocio_id,
                    'tipo_movimiento' => MaeCuentaContaSucDetalle::TIPO_COMISION_TERMINAL,
                    'cuenta_origen_id' => $maestra->id,
                    'monto_movimiento' => $monto,
                    'cuenta_destino_id' => null,
                    'descripcion_movimiento' => $descripcionComision,
                    'status' => MaeCuentaContaSucDetalle::STATUS_ACEPTADO,
                    'deleted' => MaeCuentaContaSucDetalle::DELETED_NO,
                    'created_by' => $auditId,
                    'updated_by' => $auditId,
                ]);
            }

            return $creados;
        });
    }

    /**
     * Sobrante de gerencia (diferencia_gerencia > 0): entrada a cuenta maestra
     * con origen en la cuenta de la sucursal del corte.
     */
    public function registrarSobranteCorteGerencia(TurnoCaja $turno, User|Staff $actor): ?MaeCuentaContaSucDetalle
    {
        $sobrante = round((float) ($turno->diferencia_gerencia ?? 0), 2);
        if ($sobrante <= 0) {
            return null;
        }

        $turno->loadMissing('sucursal:id,name');
        $sucursalNombre = trim((string) ($turno->sucursal?->name ?? 'SUCURSAL'));
        $descripcion = $this->descripcionSobranteCorteGerencia($turno);

        $maestra = MaeCuentaContaSuc::query()
            ->where('negocio_id', $turno->negocio_id)
            ->where('tipo_cuenta', MaeCuentaContaSuc::TIPO_MAESTRA)
            ->where('deleted', MaeCuentaContaSuc::DELETED_NO)
            ->where('status', MaeCuentaContaSuc::STATUS_ACTIVO)
            ->orderBy('id')
            ->first();

        if (! $maestra) {
            throw new HttpException(422, 'No hay cuenta maestra activa para registrar el sobrante del corte.');
        }

        $subcuenta = MaeCuentaContaSuc::query()
            ->where('negocio_id', $turno->negocio_id)
            ->where('tipo_cuenta', MaeCuentaContaSuc::TIPO_SUBCUENTA)
            ->where('sucursal_id', $turno->sucursal_id)
            ->where('deleted', MaeCuentaContaSuc::DELETED_NO)
            ->where('status', MaeCuentaContaSuc::STATUS_ACTIVO)
            ->orderBy('id')
            ->first();

        if (! $subcuenta) {
            throw new HttpException(
                422,
                'No hay cuenta contable de sucursal activa para registrar el origen del sobrante.',
            );
        }

        $auditId = $this->auditUserId($actor, $turno->negocio);
        $desde = $turno->fecha_apertura ?? $turno->created_at ?? now()->subDay();

        return DB::transaction(function () use ($maestra, $subcuenta, $sobrante, $descripcion, $sucursalNombre, $auditId, $desde) {
            $existente = MaeCuentaContaSucDetalle::query()
                ->where('negocio_id', $maestra->negocio_id)
                ->where('mae_cuenta_conta_suc_id', $maestra->id)
                ->where('cuenta_origen_id', $subcuenta->id)
                ->where('cuenta_destino_id', $maestra->id)
                ->where(function ($query) use ($descripcion, $sucursalNombre) {
                    $query->where('descripcion_movimiento', $descripcion)
                        ->orWhere('descripcion_movimiento', 'SOBRANTE del CORTE de '.$sucursalNombre);
                })
                ->where('deleted', MaeCuentaContaSucDetalle::DELETED_NO)
                ->where('created_at', '>=', $desde)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $destino = $this->lockCuenta($maestra->id);
            $monto = number_format($sobrante, 2, '.', '');

            if ($existente) {
                $actual = round((float) $existente->monto_movimiento, 2);
                $delta = round($sobrante - $actual, 2);
                if (abs($delta) <= 0.009) {
                    if ($existente->descripcion_movimiento !== $descripcion) {
                        $existente->descripcion_movimiento = $descripcion;
                        $existente->updated_by = $auditId;
                        $existente->save();
                    }

                    return $existente->refresh()->load($this->detalleRelations());
                }

                if ($delta > 0) {
                    $this->credit($destino, number_format($delta, 2, '.', ''));
                } else {
                    $this->debit($destino, number_format(abs($delta), 2, '.', ''));
                }

                $existente->monto_movimiento = $monto;
                $existente->descripcion_movimiento = $descripcion;
                $existente->updated_by = $auditId;
                $existente->save();

                return $existente->refresh()->load($this->detalleRelations());
            }

            $this->credit($destino, $monto);

            return $maestra->detalles()->create([
                'negocio_id' => $maestra->negocio_id,
                'tipo_movimiento' => MaeCuentaContaSucDetalle::TIPO_DEPOSITO,
                'cuenta_origen_id' => $subcuenta->id,
                'monto_movimiento' => $monto,
                'cuenta_destino_id' => $maestra->id,
                'descripcion_movimiento' => $descripcion,
                'status' => MaeCuentaContaSucDetalle::STATUS_ACEPTADO,
                'deleted' => MaeCuentaContaSucDetalle::DELETED_NO,
                'created_by' => $auditId,
                'updated_by' => $auditId,
            ])->refresh()->load($this->detalleRelations());
        });
    }

    /**
     * Al cerrar el corte del administrador: descuenta de la cuenta de sucursal
     * los gastos registrados en el turno.
     *
     * @return list<MaeCuentaContaSucDetalle>
     */
    public function registrarGastosCorteSucursal(TurnoCaja $turno, User|Staff $actor): array
    {
        $turno->loadMissing('sucursal:id,name');

        $lineas = [
            [
                'tipo' => MaeCuentaContaSucDetalle::TIPO_PAGO_PROVEEDOR,
                'monto' => round((float) $turno->total_pagos_proveedores, 2),
                'label' => 'pagos a proveedores',
            ],
            [
                'tipo' => MaeCuentaContaSucDetalle::TIPO_GASTO_OPERATIVO,
                'monto' => round((float) $turno->total_gastos_operativos, 2),
                'label' => 'gastos operativos',
            ],
            [
                'tipo' => MaeCuentaContaSucDetalle::TIPO_RETIRO_EFECTIVO,
                'monto' => round((float) $turno->total_retiros_efectivo, 2),
                'label' => 'retiros de efectivo',
            ],
        ];

        $total = round(array_sum(array_column($lineas, 'monto')), 2);
        if ($total <= 0) {
            return [];
        }

        $subcuenta = MaeCuentaContaSuc::query()
            ->where('negocio_id', $turno->negocio_id)
            ->where('tipo_cuenta', MaeCuentaContaSuc::TIPO_SUBCUENTA)
            ->where('sucursal_id', $turno->sucursal_id)
            ->where('deleted', MaeCuentaContaSuc::DELETED_NO)
            ->where('status', MaeCuentaContaSuc::STATUS_ACTIVO)
            ->orderBy('id')
            ->first();

        if (! $subcuenta) {
            return [];
        }

        $descripcionBase = $this->descripcionCorteGerencia($turno);
        $auditId = $this->auditUserId($actor, $turno->negocio);
        $creados = [];

        return DB::transaction(function () use ($subcuenta, $lineas, $descripcionBase, $auditId, $creados) {
            $cuenta = $this->lockCuenta($subcuenta->id);

            foreach ($lineas as $linea) {
                if ($linea['monto'] <= 0) {
                    continue;
                }

                $monto = number_format($linea['monto'], 2, '.', '');
                $this->debit($cuenta, $monto);
                $cuenta->refresh();

                $creados[] = $cuenta->detalles()->create([
                    'negocio_id' => $cuenta->negocio_id,
                    'tipo_movimiento' => $linea['tipo'],
                    'cuenta_origen_id' => $cuenta->id,
                    'monto_movimiento' => $monto,
                    'cuenta_destino_id' => null,
                    'descripcion_movimiento' => "{$descripcionBase} ({$linea['label']})",
                    'status' => MaeCuentaContaSucDetalle::STATUS_ACEPTADO,
                    'deleted' => MaeCuentaContaSucDetalle::DELETED_NO,
                    'created_by' => $auditId,
                    'updated_by' => $auditId,
                ]);
            }

            return $creados;
        });
    }

    /**
     * @param  array{descripcion_movimiento?: string}  $data
     */
    private function crearGastoDirecto(
        MaeCuentaContaSuc $cuenta,
        User|Staff $actor,
        string $tipo,
        string $monto,
        array $data,
    ): MaeCuentaContaSucDetalle {
        $auditId = $this->auditUserId($actor, $cuenta->negocio);

        return DB::transaction(function () use ($cuenta, $tipo, $monto, $data, $auditId) {
            $cuentaLocked = $this->lockCuenta($cuenta->id);
            $this->debit($cuentaLocked, $monto);

            return $cuentaLocked->detalles()->create([
                'negocio_id' => $cuentaLocked->negocio_id,
                'tipo_movimiento' => $tipo,
                'cuenta_origen_id' => $cuentaLocked->id,
                'monto_movimiento' => $monto,
                'cuenta_destino_id' => null,
                'descripcion_movimiento' => trim((string) $data['descripcion_movimiento']),
                'status' => MaeCuentaContaSucDetalle::STATUS_ACEPTADO,
                'deleted' => MaeCuentaContaSucDetalle::DELETED_NO,
                'created_by' => $auditId,
                'updated_by' => $auditId,
            ])->refresh()->load($this->detalleRelations());
        });
    }

    private function descripcionCorteGerencia(TurnoCaja $turno): string
    {
        $fecha = $turno->fecha_cierre
            ?? $turno->fecha_cierre_cajera
            ?? $turno->fecha_apertura
            ?? now();

        $fechaTxt = $fecha->timezone(config('app.timezone'))->format('d/m/Y');
        $sucursal = trim((string) ($turno->sucursal?->name ?? 'SUCURSAL'));

        return "CORTE del {$fechaTxt} de {$sucursal}";
    }

    private function descripcionComisionTerminalCorteGerencia(TurnoCaja $turno, int $porcentaje): string
    {
        return $this->descripcionCorteGerencia($turno)." (comisión de terminal {$porcentaje}%)";
    }

    private function porcentajeComisionVentaTarjeta(TurnoCaja $turno): int
    {
        $porcentaje = (int) ($turno->negocio?->comision_venta_tarjeta ?? 3);

        return max(0, min(100, $porcentaje));
    }

    private function montoComisionTerminal(float $montoTarjeta, int $porcentaje): float
    {
        if ($montoTarjeta <= 0 || $porcentaje <= 0) {
            return 0.0;
        }

        return round($montoTarjeta * $porcentaje / 100, 2);
    }

    private function descripcionSobranteCorteGerencia(TurnoCaja $turno): string
    {
        $fecha = $turno->fecha_cierre
            ?? $turno->fecha_cierre_cajera
            ?? $turno->fecha_apertura
            ?? now();

        $fechaTxt = $fecha->timezone(config('app.timezone'))->format('d/m/Y');
        $sucursal = trim((string) ($turno->sucursal?->name ?? 'SUCURSAL'));

        return "SOBRANTE del CORTE del {$fechaTxt} de {$sucursal}";
    }

    public function listForNegocio(
        Negocio $negocio,
        int $perPage = 15,
        ?int $cuentaId = null,
        ?string $tipoMovimiento = null,
        ?int $status = null,
        bool $includeDeleted = false,
    ): LengthAwarePaginator {
        $query = $negocio->cuentasContablesDetalles()
            ->with($this->detalleRelations())
            ->latest();

        if (! $includeDeleted) {
            $query->where('mae_cuenta_conta_suc_detalle.deleted', MaeCuentaContaSucDetalle::DELETED_NO);
        }

        if ($cuentaId !== null) {
            $query->where('mae_cuenta_conta_suc_id', $cuentaId);
        }

        $tipo = MaeCuentaContaSucDetalle::normalizeTipoMovimiento($tipoMovimiento);
        if ($tipo !== null) {
            $query->where('tipo_movimiento', $tipo);
        }

        if ($status !== null) {
            $query->where('mae_cuenta_conta_suc_detalle.status', $status);
        }

        return $query->paginate($perPage);
    }

    public function listForCuenta(
        MaeCuentaContaSuc $cuenta,
        int $perPage = 15,
        bool $includeDeleted = false,
    ): LengthAwarePaginator {
        $query = MaeCuentaContaSucDetalle::query()
            ->where('negocio_id', $cuenta->negocio_id)
            ->where(function ($q) use ($cuenta) {
                $q->where('mae_cuenta_conta_suc_id', $cuenta->id)
                    ->orWhere('cuenta_origen_id', $cuenta->id)
                    ->orWhere('cuenta_destino_id', $cuenta->id);
            })
            ->with($this->detalleRelations())
            ->latest();

        if (! $includeDeleted) {
            $query->where('deleted', MaeCuentaContaSucDetalle::DELETED_NO);
        }

        return $query->paginate($perPage);
    }

    public function listPendientesBySucursal(
        Negocio $negocio,
        User|Staff $actor,
        ?int $sucursalId,
        int $perPage = 15,
    ): LengthAwarePaginator {
        $sucursalId = $this->resolvedSucursalForPendientes($negocio, $actor, $sucursalId);

        return MaeCuentaContaSucDetalle::query()
            ->where('mae_cuenta_conta_suc_detalle.negocio_id', $negocio->id)
            ->where('mae_cuenta_conta_suc_detalle.status', MaeCuentaContaSucDetalle::STATUS_PENDIENTE)
            ->where('mae_cuenta_conta_suc_detalle.deleted', MaeCuentaContaSucDetalle::DELETED_NO)
            ->where(function ($q) use ($negocio, $sucursalId) {
                // Transferencia entrada: cuenta destino = subcuenta de la sucursal
                $q->whereHas('cuentaDestino', function ($destino) use ($negocio, $sucursalId) {
                    $destino->where('negocio_id', $negocio->id)
                        ->where('sucursal_id', $sucursalId)
                        ->where('tipo_cuenta', MaeCuentaContaSuc::TIPO_SUBCUENTA)
                        ->where('deleted', MaeCuentaContaSuc::DELETED_NO);
                })
                // Retiro salida: cuenta origen = subcuenta de la sucursal
                    ->orWhereHas('cuentaOrigen', function ($origen) use ($negocio, $sucursalId) {
                        $origen->where('negocio_id', $negocio->id)
                            ->where('sucursal_id', $sucursalId)
                            ->where('tipo_cuenta', MaeCuentaContaSuc::TIPO_SUBCUENTA)
                            ->where('deleted', MaeCuentaContaSuc::DELETED_NO);
                    });
            })
            ->with($this->detalleRelations())
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Sucursal efectiva para listar pendientes: staff usa la suya; maestro envía sucursal_id.
     */
    public function sucursalIdForPendientes(Negocio $negocio, User|Staff $actor, ?int $sucursalId): int
    {
        return $this->resolvedSucursalForPendientes($negocio, $actor, $sucursalId);
    }

    public function findForNegocio(Negocio $negocio, int $detalleId, bool $includeDeleted = false): MaeCuentaContaSucDetalle
    {
        $query = $negocio->cuentasContablesDetalles()
            ->with($this->detalleRelations());

        if (! $includeDeleted) {
            $query->where('deleted', MaeCuentaContaSucDetalle::DELETED_NO);
        }

        return $query->findOrFail($detalleId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MaeCuentaContaSucDetalle $detalle, User|Staff $actor, array $data): MaeCuentaContaSucDetalle
    {
        if ($detalle->isDeleted()) {
            throw new HttpException(422, 'No puedes actualizar un movimiento eliminado.');
        }

        if ($detalle->isPendiente()) {
            $bloqueados = ['tipo_movimiento', 'monto_movimiento', 'cuenta_origen_id', 'cuenta_destino_id', 'status'];
            foreach ($bloqueados as $campo) {
                if (array_key_exists($campo, $data)) {
                    throw new HttpException(422, 'Una solicitud pendiente solo puede cambiar la descripción. Acéptala o recházala para resolver el saldo.');
                }
            }
        }

        if (array_key_exists('tipo_movimiento', $data)) {
            $data['tipo_movimiento'] = $this->resolvedTipoMovimiento($data['tipo_movimiento']);
        }

        if (array_key_exists('monto_movimiento', $data)) {
            $data['monto_movimiento'] = $this->resolvedMonto($data['monto_movimiento']);
        }

        $tipo = $data['tipo_movimiento'] ?? $detalle->tipo_movimiento;
        if (
            array_key_exists('tipo_movimiento', $data)
            || array_key_exists('cuenta_origen_id', $data)
            || array_key_exists('cuenta_destino_id', $data)
        ) {
            [$origenId, $destinoId] = $this->resolvedCuentasMovimiento(
                $detalle->cuenta,
                $tipo,
                array_key_exists('cuenta_origen_id', $data) ? $data['cuenta_origen_id'] : $detalle->cuenta_origen_id,
                array_key_exists('cuenta_destino_id', $data) ? $data['cuenta_destino_id'] : $detalle->cuenta_destino_id,
            );
            $data['cuenta_origen_id'] = $origenId;
            $data['cuenta_destino_id'] = $destinoId;
        }

        $detalle->fill($data);
        $detalle->updated_by = $this->auditUserId($actor, $detalle->negocio);
        $detalle->save();

        return $detalle->refresh()->load($this->detalleRelations());
    }

    public function darDeBaja(MaeCuentaContaSucDetalle $detalle, User|Staff $actor): MaeCuentaContaSucDetalle
    {
        if ($detalle->isDeleted()) {
            throw new HttpException(422, 'El movimiento ya está eliminado.');
        }

        if ($detalle->isPendiente()) {
            throw new HttpException(422, 'No puedes eliminar una solicitud pendiente. El encargado de sucursal debe aceptarla o rechazarla.');
        }

        $detalle->deleted = MaeCuentaContaSucDetalle::DELETED_YES;
        $detalle->status = MaeCuentaContaSucDetalle::STATUS_INACTIVO;
        $detalle->updated_by = $this->auditUserId($actor, $detalle->negocio);
        $detalle->save();

        return $detalle->refresh()->load($this->detalleRelations());
    }

    public function aceptar(MaeCuentaContaSucDetalle $detalle, User|Staff $actor): MaeCuentaContaSucDetalle
    {
        $this->assertPuedeResolverSolicitud($detalle, $actor);

        return DB::transaction(function () use ($detalle, $actor) {
            $detalle = MaeCuentaContaSucDetalle::query()->whereKey($detalle->id)->lockForUpdate()->firstOrFail();

            if ($detalle->isDeleted() || (int) $detalle->status !== MaeCuentaContaSucDetalle::STATUS_PENDIENTE) {
                throw new HttpException(422, 'Solo se pueden aceptar solicitudes pendientes.');
            }

            $destino = $this->lockCuenta((int) $detalle->cuenta_destino_id);
            $monto = (string) $detalle->monto_movimiento;

            if ($detalle->isRetiro()) {
                // Retiro sucursal → matriz: descuenta sucursal y abona matriz al autorizar
                $origen = $this->lockCuenta((int) $detalle->cuenta_origen_id);
                $this->debit($origen, $monto);
                $this->credit($destino, $monto);
            } else {
                // Transferencia matriz → sucursal: maestra ya descontada al crear
                $this->credit($destino, $monto);
            }

            $detalle->status = MaeCuentaContaSucDetalle::STATUS_ACEPTADO;
            $detalle->updated_by = $this->auditUserId($actor, $detalle->negocio);
            $detalle->save();

            $this->registrarMovimientoAceptadoEnTurno($detalle->refresh(), $actor);

            return $detalle->refresh()->load($this->detalleRelations());
        });
    }

    public function rechazar(MaeCuentaContaSucDetalle $detalle, User|Staff $actor): MaeCuentaContaSucDetalle
    {
        $this->assertPuedeResolverSolicitud($detalle, $actor);

        return DB::transaction(function () use ($detalle, $actor) {
            $detalle = MaeCuentaContaSucDetalle::query()->whereKey($detalle->id)->lockForUpdate()->firstOrFail();

            if ($detalle->isDeleted() || (int) $detalle->status !== MaeCuentaContaSucDetalle::STATUS_PENDIENTE) {
                throw new HttpException(422, 'Solo se pueden rechazar solicitudes pendientes.');
            }

            // Retiro: no se movió saldo al crear → solo marca rechazado.
            // Transferencia entrada: devolver a maestra lo descontado al crear.
            if (! $detalle->isRetiro()) {
                $origen = $this->lockCuenta((int) $detalle->cuenta_origen_id);
                $this->credit($origen, (string) $detalle->monto_movimiento);
            }

            $detalle->status = MaeCuentaContaSucDetalle::STATUS_RECHAZADO;
            $detalle->updated_by = $this->auditUserId($actor, $detalle->negocio);
            $detalle->save();

            return $detalle->refresh()->load($this->detalleRelations());
        });
    }

    /**
     * @return array<int, string>
     */
    private function detalleRelations(): array
    {
        $cuentaCols = 'id,negocio_id,tipo_cuenta,sucursal_id,titulo_cuenta,saldo,status,deleted';

        return [
            'cuenta:'.$cuentaCols,
            'cuentaOrigen:'.$cuentaCols,
            'cuentaDestino:'.$cuentaCols,
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ];
    }

    private function resolvedMonto(mixed $monto): string
    {
        $value = round((float) $monto, 2);
        if ($value <= 0) {
            throw new HttpException(422, 'El monto del movimiento debe ser mayor a cero.');
        }

        return number_format($value, 2, '.', '');
    }

    /**
     * @return array{0: int|null, 1: int}
     */
    private function resolvedCuentasMovimiento(
        MaeCuentaContaSuc $cuenta,
        string $tipo,
        mixed $origenId,
        mixed $destinoId,
    ): array {
        $destino = (int) ($destinoId ?: $cuenta->id);
        $this->assertCuentaDelNegocio($cuenta->negocio_id, $destino, 'destino');

        if ($tipo === MaeCuentaContaSucDetalle::TIPO_DEPOSITO) {
            $origen = (int) $origenId;

            return [$origen > 0 ? $this->assertCuentaDelNegocio($cuenta->negocio_id, $origen, 'origen') : null, $destino];
        }

        $origen = (int) $origenId;
        if ($origen < 1) {
            $label = match ($tipo) {
                MaeCuentaContaSucDetalle::TIPO_RETIRO => 'retiro',
                MaeCuentaContaSucDetalle::TIPO_VENTA_EFECTIVO,
                MaeCuentaContaSucDetalle::TIPO_VENTA_TARJETA => 'venta de corte',
                default => 'transferencia',
            };
            throw new HttpException(422, "La cuenta origen es obligatoria cuando el movimiento es {$label}.");
        }

        $this->assertCuentaDelNegocio($cuenta->negocio_id, $origen, 'origen');

        if ($origen === $destino) {
            throw new HttpException(422, 'La cuenta origen y la cuenta destino deben ser distintas.');
        }

        return [$origen, $destino];
    }

    private function assertCuentaDelNegocio(int $negocioId, int $cuentaId, string $rol): int
    {
        $existe = MaeCuentaContaSuc::query()
            ->where('negocio_id', $negocioId)
            ->whereKey($cuentaId)
            ->where('deleted', MaeCuentaContaSuc::DELETED_NO)
            ->exists();

        if (! $existe) {
            throw new HttpException(422, "La cuenta {$rol} no existe, no pertenece a tu negocio o está eliminada.");
        }

        return $cuentaId;
    }

    private function resolvedTipoMovimiento(mixed $tipo): string
    {
        $normalized = MaeCuentaContaSucDetalle::normalizeTipoMovimiento($tipo);
        if ($normalized === null) {
            throw new HttpException(422, 'El tipo de movimiento debe ser deposito, transferencia, retiro, gasto, gasto_operativo, pago_proveedor, retiro_efectivo, venta_efectivo, venta_tarjeta o comision_terminal.');
        }

        return $normalized;
    }

    private function esSolicitudMaestraASubcuenta(?MaeCuentaContaSuc $origen, MaeCuentaContaSuc $destino): bool
    {
        return $origen !== null
            && $origen->isMaestra()
            && $destino->tipo_cuenta === MaeCuentaContaSuc::TIPO_SUBCUENTA;
    }

    private function esSolicitudSubcuentaAMaestra(?MaeCuentaContaSuc $origen, MaeCuentaContaSuc $destino): bool
    {
        return $origen !== null
            && $origen->tipo_cuenta === MaeCuentaContaSuc::TIPO_SUBCUENTA
            && $destino->isMaestra();
    }

    private function aplicarMovimientoInmediato(
        string $tipo,
        ?MaeCuentaContaSuc $origen,
        MaeCuentaContaSuc $destino,
        string $monto,
    ): void {
        // Ventas de corte y depósito: solo abonan destino (no descuentan origen).
        if (in_array($tipo, [
            MaeCuentaContaSucDetalle::TIPO_DEPOSITO,
            ...MaeCuentaContaSucDetalle::TIPOS_VENTA_CORTE,
        ], true)) {
            $this->credit($destino, $monto);

            return;
        }

        if (in_array($tipo, [
            MaeCuentaContaSucDetalle::TIPO_TRANSFERENCIA,
            MaeCuentaContaSucDetalle::TIPO_RETIRO,
        ], true)) {
            if ($origen === null) {
                throw new HttpException(422, 'La cuenta origen es obligatoria cuando el movimiento es transferencia o retiro.');
            }

            $this->debit($origen, $monto);
            $this->credit($destino, $monto);

            return;
        }

        $this->credit($destino, $monto);
    }

    private function lockCuenta(int $cuentaId): MaeCuentaContaSuc
    {
        $cuenta = MaeCuentaContaSuc::query()
            ->whereKey($cuentaId)
            ->where('deleted', MaeCuentaContaSuc::DELETED_NO)
            ->lockForUpdate()
            ->first();

        if (! $cuenta) {
            throw new HttpException(422, 'La cuenta no existe o está eliminada.');
        }

        return $cuenta;
    }

    private function assertSaldoSuficiente(MaeCuentaContaSuc $cuenta, string $monto): void
    {
        $saldo = round((float) $cuenta->saldo, 2);
        $valor = round((float) $monto, 2);

        if ($saldo + 0.0001 < $valor) {
            throw new HttpException(422, 'Saldo insuficiente en la cuenta origen.');
        }
    }

    private function debit(MaeCuentaContaSuc $cuenta, string $monto): void
    {
        $this->assertSaldoSuficiente($cuenta, $monto);

        $saldo = round((float) $cuenta->saldo, 2);
        $valor = round((float) $monto, 2);
        $cuenta->saldo = number_format($saldo - $valor, 2, '.', '');
        $cuenta->save();
    }

    /**
     * Al aceptar depósito (matriz → sucursal) o retiro (sucursal → matriz),
     * replica el movimiento en el turno pendiente de validación de esa sucursal.
     */
    private function registrarMovimientoAceptadoEnTurno(
        MaeCuentaContaSucDetalle $detalle,
        User|Staff $actor,
    ): void {
        $detalle->loadMissing(['cuentaDestino', 'cuentaOrigen']);
        $cuentaSucursal = $detalle->isRetiro() ? $detalle->cuentaOrigen : $detalle->cuentaDestino;
        $sucursalId = (int) ($cuentaSucursal?->sucursal_id ?? 0);
        if ($sucursalId < 1) {
            return;
        }

        /** @var TurnoCajaService $turnos */
        $turnos = app(TurnoCajaService::class);
        $turno = $turnos->turnoPendienteDeValidacion((int) $detalle->negocio_id, $sucursalId);
        if (! $turno) {
            return;
        }

        $turnos->registrarMovimientoContableAceptado(
            $turno,
            $actor,
            $detalle->isRetiro(),
            (string) $detalle->descripcion_movimiento,
            (float) $detalle->monto_movimiento,
        );
    }

    private function credit(MaeCuentaContaSuc $cuenta, string $monto): void
    {
        $cuenta->saldo = number_format(round((float) $cuenta->saldo + (float) $monto, 2), 2, '.', '');
        $cuenta->save();
    }

    private function assertPuedeResolverSolicitud(MaeCuentaContaSucDetalle $detalle, User|Staff $actor): void
    {
        $detalle->loadMissing(['cuentaDestino', 'cuentaOrigen']);

        if ($actor instanceof User) {
            return;
        }

        // Retiro: autoriza staff de la sucursal origen. Transferencia entrada: staff de destino.
        $cuentaSucursal = $detalle->isRetiro() ? $detalle->cuentaOrigen : $detalle->cuentaDestino;

        if (! $cuentaSucursal) {
            throw new HttpException(422, 'La solicitud no tiene la cuenta de sucursal asociada.');
        }

        if ((int) $actor->sucursal_id !== (int) $cuentaSucursal->sucursal_id) {
            throw new HttpException(
                403,
                $detalle->isRetiro()
                    ? 'Solo el encargado de la sucursal origen puede aceptar o rechazar este retiro.'
                    : 'Solo el encargado de la sucursal destino puede aceptar o rechazar esta solicitud.',
            );
        }
    }

    private function resolvedSucursalForPendientes(Negocio $negocio, User|Staff $actor, ?int $sucursalId): int
    {
        if ($actor instanceof Staff) {
            $id = (int) $actor->sucursal_id;
            if ($id < 1) {
                throw new HttpException(422, 'El usuario staff no tiene una sucursal asignada.');
            }

            return $id;
        }

        $id = (int) $sucursalId;
        if ($id < 1) {
            throw new HttpException(422, 'La sucursal es obligatoria para listar solicitudes pendientes.');
        }

        $exists = Sucursal::query()
            ->where('negocio_id', $negocio->id)
            ->whereKey($id)
            ->exists();

        if (! $exists) {
            throw new HttpException(422, 'La sucursal no existe o no pertenece a tu negocio.');
        }

        return $id;
    }
}
