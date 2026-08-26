<?php

namespace App\Services;

use App\Models\MaeCuentaContaSuc;
use App\Models\Negocio;
use App\Models\Staff;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MaeCuentaContaSucService
{
    use ResolvesNegocioFromActor;

    /**
     * @param  array{
     *     tipo_cuenta: string,
     *     sucursal_id?: int|null,
     *     titulo_cuenta: string,
     *     descripcion_cuenta?: string|null,
     *     status?: int
     * }  $data
     */
    public function create(Negocio $negocio, User|Staff $actor, array $data): MaeCuentaContaSuc
    {
        $tipo = $this->resolvedTipoCuenta($data['tipo_cuenta'] ?? null);
        $sucursalId = $this->resolvedSucursalId($negocio, $tipo, $data['sucursal_id'] ?? null);
        $auditId = $this->auditUserId($actor, $negocio);

        return $negocio->cuentasContables()->create([
            'tipo_cuenta' => $tipo,
            'sucursal_id' => $sucursalId,
            'titulo_cuenta' => trim((string) $data['titulo_cuenta']),
            'descripcion_cuenta' => isset($data['descripcion_cuenta'])
                ? trim((string) $data['descripcion_cuenta'])
                : null,
            'status' => $data['status'] ?? MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $auditId,
            'updated_by' => $auditId,
        ]);
    }

    public function listForNegocio(
        Negocio $negocio,
        int $perPage = 15,
        ?int $status = null,
        ?string $tipoCuenta = null,
        ?int $sucursalId = null,
        bool $includeDeleted = false,
    ): LengthAwarePaginator {
        $query = $negocio->cuentasContables()
            ->with([
                'sucursal:id,negocio_id,type,name',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->latest();

        if (! $includeDeleted) {
            $query->where('deleted', MaeCuentaContaSuc::DELETED_NO);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        $tipo = MaeCuentaContaSuc::normalizeTipoCuenta($tipoCuenta);
        if ($tipo !== null) {
            $query->where('tipo_cuenta', $tipo);
        }

        if ($sucursalId !== null) {
            $query->where('sucursal_id', $sucursalId);
        }

        return $query->paginate($perPage);
    }

    public function findForNegocio(Negocio $negocio, int $cuentaId, bool $includeDeleted = false): MaeCuentaContaSuc
    {
        $query = $negocio->cuentasContables()
            ->with([
                'sucursal:id,negocio_id,type,name',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ]);

        if (! $includeDeleted) {
            $query->where('deleted', MaeCuentaContaSuc::DELETED_NO);
        }

        return $query->findOrFail($cuentaId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(MaeCuentaContaSuc $cuenta, User|Staff $actor, array $data): MaeCuentaContaSuc
    {
        if ($cuenta->isDeleted()) {
            throw new HttpException(422, 'No puedes actualizar una cuenta contable eliminada.');
        }

        $tipo = array_key_exists('tipo_cuenta', $data)
            ? $this->resolvedTipoCuenta($data['tipo_cuenta'])
            : $cuenta->tipo_cuenta;

        if (array_key_exists('tipo_cuenta', $data) || array_key_exists('sucursal_id', $data)) {
            $sucursalInput = array_key_exists('sucursal_id', $data)
                ? $data['sucursal_id']
                : $cuenta->sucursal_id;
            $data['sucursal_id'] = $this->resolvedSucursalId($cuenta->negocio, $tipo, $sucursalInput);
            $data['tipo_cuenta'] = $tipo;
        }

        $cuenta->fill($data);
        $cuenta->updated_by = $this->auditUserId($actor, $cuenta->negocio);
        $cuenta->save();

        return $cuenta->refresh()->load([
            'sucursal:id,negocio_id,type,name',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    public function darDeBaja(MaeCuentaContaSuc $cuenta, User|Staff $actor): MaeCuentaContaSuc
    {
        if ($cuenta->isDeleted()) {
            throw new HttpException(422, 'La cuenta contable ya está eliminada.');
        }

        $cuenta->deleted = MaeCuentaContaSuc::DELETED_YES;
        $cuenta->status = MaeCuentaContaSuc::STATUS_INACTIVO;
        $cuenta->updated_by = $this->auditUserId($actor, $cuenta->negocio);
        $cuenta->save();

        return $cuenta->refresh()->load([
            'sucursal:id,negocio_id,type,name',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    private function resolvedTipoCuenta(mixed $tipo): string
    {
        $normalized = MaeCuentaContaSuc::normalizeTipoCuenta($tipo);
        if ($normalized === null) {
            throw new HttpException(422, 'El tipo de cuenta debe ser maestra o subcuenta.');
        }

        return $normalized;
    }

    private function resolvedSucursalId(Negocio $negocio, string $tipo, mixed $sucursalId): ?int
    {
        if ($tipo === MaeCuentaContaSuc::TIPO_MAESTRA) {
            return null;
        }

        $id = (int) $sucursalId;
        if ($id < 1) {
            throw new HttpException(422, 'La sucursal es obligatoria cuando la cuenta es subcuenta.');
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
