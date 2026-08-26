<?php

namespace App\Services;

use App\Models\MaeCuentaContaSuc;
use App\Models\MaeCuentaContaSucDetalle;
use App\Models\Negocio;
use App\Models\Staff;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

        return $cuenta->detalles()->create([
            'negocio_id' => $cuenta->negocio_id,
            'tipo_movimiento' => $tipo,
            'cuenta_origen_id' => $origenId,
            'monto_movimiento' => $monto,
            'cuenta_destino_id' => $destinoId,
            'descripcion_movimiento' => trim((string) $data['descripcion_movimiento']),
            'status' => $data['status'] ?? MaeCuentaContaSucDetalle::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSucDetalle::DELETED_NO,
            'created_by' => $auditId,
            'updated_by' => $auditId,
        ]);
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
        $query = $cuenta->detalles()
            ->with($this->detalleRelations())
            ->latest();

        if (! $includeDeleted) {
            $query->where('deleted', MaeCuentaContaSucDetalle::DELETED_NO);
        }

        return $query->paginate($perPage);
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

        $detalle->deleted = MaeCuentaContaSucDetalle::DELETED_YES;
        $detalle->status = MaeCuentaContaSucDetalle::STATUS_INACTIVO;
        $detalle->updated_by = $this->auditUserId($actor, $detalle->negocio);
        $detalle->save();

        return $detalle->refresh()->load($this->detalleRelations());
    }

    /**
     * @return array<int, string>
     */
    private function detalleRelations(): array
    {
        $cuentaCols = 'id,negocio_id,tipo_cuenta,sucursal_id,titulo_cuenta,status,deleted';

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
}
