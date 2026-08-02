<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RoleService
{
    use ResolvesNegocioFromActor;

    /**
     * @param  array{name: string, permissions?: array<string, bool>|null, status?: bool}  $data
     */
    public function create(Negocio $negocio, User|Staff $user, array $data): Role
    {
        $auditId = $this->auditUserId($user, $negocio);

        return $negocio->roles()->create([
            'name' => $data['name'],
            'permissions' => Role::normalizePermissions($data['permissions'] ?? null),
            'status' => $data['status'] ?? true,
            'created_by' => $auditId,
            'updated_by' => $auditId,
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
    public function update(Role $role, User|Staff $user, array $data): Role
    {
        if (array_key_exists('permissions', $data)) {
            $data['permissions'] = Role::normalizePermissions(
                is_array($data['permissions']) ? $data['permissions'] : null
            );
        }

        $role->fill($data);
        $role->updated_by = $this->auditUserId($user, $role->negocio);
        $role->save();

        return $role->refresh()->load(['createdBy:id,name,email', 'updatedBy:id,name,email']);
    }

    public function setStatus(Role $role, User|Staff $user, bool $status): Role
    {
        $role->status = $status;
        $role->updated_by = $this->auditUserId($user, $role->negocio);
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
