<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SucursalService
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
     * @param  array{
     *     type: string,
     *     name: string,
     *     is_active?: bool,
     *     street?: string|null,
     *     neighborhood?: string|null,
     *     city?: string|null,
     *     state?: string|null,
     *     postal_code?: string|null,
     *     opened_year?: int|null
     * }  $data
     */
    public function create(Negocio $negocio, array $data): Sucursal
    {
        return $negocio->sucursales()->create([
            'type' => $data['type'],
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? true,
            'street' => $data['street'] ?? null,
            'neighborhood' => $data['neighborhood'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'opened_year' => $data['opened_year'] ?? null,
        ]);
    }

    public function listForNegocio(Negocio $negocio, int $perPage = 15): LengthAwarePaginator
    {
        return $negocio->sucursales()
            ->latest()
            ->paginate($perPage);
    }

    public function findForNegocio(Negocio $negocio, int $sucursalId): Sucursal
    {
        return $negocio->sucursales()->findOrFail($sucursalId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Sucursal $sucursal, array $data): Sucursal
    {
        $sucursal->fill($data);
        $sucursal->save();

        return $sucursal->refresh();
    }
}
