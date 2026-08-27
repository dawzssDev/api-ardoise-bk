<?php

namespace App\Services;

use App\Models\MaeCuentaContaSuc;
use App\Models\MaeCuentaContaSucDetalle;
use App\Models\Negocio;
use App\Models\Staff;
use App\Models\Sucursal;
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
            $pendiente = $this->esSolicitudMaestraASubcuenta($origen, $destino);
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
            ->whereHas('cuentaDestino', function ($q) use ($negocio, $sucursalId) {
                $q->where('negocio_id', $negocio->id)
                    ->where('sucursal_id', $sucursalId)
                    ->where('deleted', MaeCuentaContaSuc::DELETED_NO);
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
            $this->credit($destino, (string) $detalle->monto_movimiento);

            $detalle->status = MaeCuentaContaSucDetalle::STATUS_ACEPTADO;
            $detalle->updated_by = $this->auditUserId($actor, $detalle->negocio);
            $detalle->save();

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

            $origen = $this->lockCuenta((int) $detalle->cuenta_origen_id);
            $this->credit($origen, (string) $detalle->monto_movimiento);

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
            throw new HttpException(422, 'La cuenta origen es obligatoria cuando el movimiento es transferencia.');
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
            throw new HttpException(422, 'El tipo de movimiento debe ser deposito o transferencia.');
        }

        return $normalized;
    }

    private function esSolicitudMaestraASubcuenta(?MaeCuentaContaSuc $origen, MaeCuentaContaSuc $destino): bool
    {
        return $origen !== null
            && $origen->isMaestra()
            && $destino->tipo_cuenta === MaeCuentaContaSuc::TIPO_SUBCUENTA;
    }

    private function aplicarMovimientoInmediato(
        string $tipo,
        ?MaeCuentaContaSuc $origen,
        MaeCuentaContaSuc $destino,
        string $monto,
    ): void {
        if ($tipo === MaeCuentaContaSucDetalle::TIPO_TRANSFERENCIA) {
            if ($origen === null) {
                throw new HttpException(422, 'La cuenta origen es obligatoria cuando el movimiento es transferencia.');
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

    private function debit(MaeCuentaContaSuc $cuenta, string $monto): void
    {
        $saldo = round((float) $cuenta->saldo, 2);
        $valor = round((float) $monto, 2);

        if ($saldo + 0.0001 < $valor) {
            throw new HttpException(422, 'Saldo insuficiente en la cuenta origen.');
        }

        $cuenta->saldo = number_format($saldo - $valor, 2, '.', '');
        $cuenta->save();
    }

    private function credit(MaeCuentaContaSuc $cuenta, string $monto): void
    {
        $cuenta->saldo = number_format(round((float) $cuenta->saldo + (float) $monto, 2), 2, '.', '');
        $cuenta->save();
    }

    private function assertPuedeResolverSolicitud(MaeCuentaContaSucDetalle $detalle, User|Staff $actor): void
    {
        $detalle->loadMissing('cuentaDestino');
        $destino = $detalle->cuentaDestino;

        if (! $destino) {
            throw new HttpException(422, 'La solicitud no tiene cuenta destino.');
        }

        if ($actor instanceof User) {
            return;
        }

        if ((int) $actor->sucursal_id !== (int) $destino->sucursal_id) {
            throw new HttpException(403, 'Solo el encargado de la sucursal destino puede aceptar o rechazar esta solicitud.');
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
