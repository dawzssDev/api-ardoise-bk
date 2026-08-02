<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RoleService
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
     * @param  array{name: string, permissions?: array<string, bool>|null, status?: bool}  $data
     */
    public function create(Negocio $negocio, User $user, array $data): Role
    {
        return $negocio->roles()->create([
            'name' => $data['name'],
            'permissions' => Role::normalizePermissions($data['permissions'] ?? null),
            'status' => $data['status'] ?? true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    public function listForNegocio(Negocio $negocio, int $perPage = 15): LengthAwarePaginator
    {
        return $negocio->roles()
            ->with(['createdBy:id,name,email', 'updatedBy:id,name,email'])
            ->latest()
            ->paginate($perPage);
    }

    public function findForNegocio(Negocio $negocio, int $roleId): Role
    {
        return $negocio->roles()
            ->with(['createdBy:id,name,email', 'updatedBy:id,name,email'])
            ->findOrFail($roleId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Role $role, User $user, array $data): Role
    {
        if (array_key_exists('permissions', $data)) {
            $data['permissions'] = Role::normalizePermissions(
                is_array($data['permissions']) ? $data['permissions'] : null
            );
        }

        $role->fill($data);
        $role->updated_by = $user->id;
        $role->save();

        return $role->refresh()->load(['createdBy:id,name,email', 'updatedBy:id,name,email']);
    }

    public function setStatus(Role $role, User $user, bool $status): Role
    {
        $role->status = $status;
        $role->updated_by = $user->id;
        $role->save();

        return $role->refresh()->load(['createdBy:id,name,email', 'updatedBy:id,name,email']);
    }

    public function delete(Role $role): void
    {
        if ($role->empleados()->exists()) {
            throw new HttpException(
                422,
                'No se puede eliminar el rol porque tiene empleados ligados.',
            );
        }

        $role->delete();
    }
}
