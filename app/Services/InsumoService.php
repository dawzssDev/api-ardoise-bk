<?php

namespace App\Services;

use App\Models\Insumo;
use App\Models\Negocio;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InsumoService
{
    public function negocioForUser(User $user): Negocio
    {
        $negocio = $user->negocio;

        if (! $negocio) {
            throw new HttpException(422, 'El usuario no tiene un negocio asociado.');
        }

        return $negocio;
    }

    /**
     * @param  array{name: string, categoria_insumo_id: int, status_insumo?: bool}  $data
     */
    public function create(Negocio $negocio, User $user, array $data): Insumo
    {
        return $negocio->insumos()->create([
            'categoria_insumo_id' => $data['categoria_insumo_id'],
            'name' => $data['name'],
            'status_insumo' => $data['status_insumo'] ?? true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
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
    public function update(Insumo $insumo, User $user, array $data): Insumo
    {
        $insumo->fill($data);
        $insumo->updated_by = $user->id;
        $insumo->save();

        return $insumo->refresh()->load([
            'categoria:id,negocio_id,name',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    public function setStatus(Insumo $insumo, User $user, bool $statusInsumo): Insumo
    {
        $insumo->status_insumo = $statusInsumo;
        $insumo->updated_by = $user->id;
        $insumo->save();

        return $insumo->refresh()->load([
            'categoria:id,negocio_id,name',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }
}
