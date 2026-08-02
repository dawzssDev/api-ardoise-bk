<?php

namespace App\Services;

use App\Models\Insumo;
use App\Models\Negocio;
use App\Models\Staff;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class InsumoService
{
    use ResolvesNegocioFromActor;

    /**
     * @param  array{name: string, categoria_insumo_id: int, status_insumo?: bool}  $data
     */
    public function create(Negocio $negocio, User|Staff $user, array $data): Insumo
    {
        $auditId = $this->auditUserId($user, $negocio);

        return $negocio->insumos()->create([
            'categoria_insumo_id' => $data['categoria_insumo_id'],
            'name' => $data['name'],
            'status_insumo' => $data['status_insumo'] ?? true,
            'created_by' => $auditId,
            'updated_by' => $auditId,
        ]);
    }

    public function listForNegocio(Negocio $negocio, int $perPage = 15): LengthAwarePaginator
    {
        return $negocio->insumos()
            ->with([
                'categoria:id,negocio_id,name',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->latest()
            ->paginate($perPage);
    }

    public function findForNegocio(Negocio $negocio, int $insumoId): Insumo
    {
        return $negocio->insumos()
            ->with([
                'categoria:id,negocio_id,name',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->findOrFail($insumoId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Insumo $insumo, User|Staff $user, array $data): Insumo
    {
        $insumo->fill($data);
        $insumo->updated_by = $this->auditUserId($user, $insumo->negocio);
        $insumo->save();

        return $insumo->refresh()->load([
            'categoria:id,negocio_id,name',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    public function setStatus(Insumo $insumo, User|Staff $user, bool $statusInsumo): Insumo
    {
        $insumo->status_insumo = $statusInsumo;
        $insumo->updated_by = $this->auditUserId($user, $insumo->negocio);
        $insumo->save();

        return $insumo->refresh()->load([
            'categoria:id,negocio_id,name',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }
}
