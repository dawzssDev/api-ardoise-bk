<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Staff;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StaffService
{
    use ResolvesNegocioFromActor;

    /**
     * @param  array{
     *     username: string,
     *     password: string,
     *     sucursal_id: int,
     *     role_id: int,
     *     empleado_id: int,
     *     status?: bool
     * }  $data
     */
    public function create(Negocio $negocio, User $user, array $data): Staff
    {
        $this->assertRelationsBelongToNegocio($negocio, $data);

        return $negocio->staff()->create([
            'username' => $data['username'],
            'password' => $data['password'],
            'sucursal_id' => $data['sucursal_id'],
            'role_id' => $data['role_id'],
            'empleado_id' => $data['empleado_id'],
            'status' => $data['status'] ?? true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    public function listForNegocio(Negocio $negocio, int $perPage = 15): LengthAwarePaginator
    {
        return $negocio->staff()
            ->with([
                'sucursal:id,negocio_id,type,name',
                'role:id,negocio_id,name,status',
                'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname,employee_number,status',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->latest()
            ->paginate($perPage);
    }

    public function findForNegocio(Negocio $negocio, int $staffId): Staff
    {
        return $negocio->staff()
            ->with([
                'sucursal:id,negocio_id,type,name',
                'role:id,negocio_id,name,status',
                'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname,employee_number,status',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->findOrFail($staffId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Staff $staff, User $user, array $data): Staff
    {
        if (
            array_key_exists('sucursal_id', $data)
            || array_key_exists('role_id', $data)
            || array_key_exists('empleado_id', $data)
        ) {
            $this->assertRelationsBelongToNegocio($staff->negocio, [
                'sucursal_id' => $data['sucursal_id'] ?? $staff->sucursal_id,
                'role_id' => $data['role_id'] ?? $staff->role_id,
                'empleado_id' => $data['empleado_id'] ?? $staff->empleado_id,
            ]);
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $staff->fill($data);
        $staff->updated_by = $user->id;
        $staff->save();

        return $staff->refresh()->load([
            'sucursal:id,negocio_id,type,name',
            'role:id,negocio_id,name,status',
            'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname,employee_number,status',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    public function setStatus(Staff $staff, User $user, bool $status): Staff
    {
        $staff->status = $status;
        $staff->updated_by = $user->id;
        $staff->save();

        return $staff->refresh()->load([
            'sucursal:id,negocio_id,type,name',
            'role:id,negocio_id,name,status',
            'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname,employee_number,status',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    public function delete(Staff $staff): void
    {
        $staff->tokens()->delete();
        $staff->delete();
    }

    /**
     * @param  array{sucursal_id: int, role_id: int, empleado_id: int}  $data
     */
    private function assertRelationsBelongToNegocio(Negocio $negocio, array $data): void
    {
        $sucursalOk = $negocio->sucursales()->whereKey($data['sucursal_id'])->exists();
        $roleOk = $negocio->roles()->whereKey($data['role_id'])->exists();
        $empleadoOk = $negocio->empleados()->whereKey($data['empleado_id'])->exists();

        if (! $sucursalOk || ! $roleOk || ! $empleadoOk) {
            throw new HttpException(422, 'Sucursal, rol o empleado no pertenecen a tu negocio.');
        }
    }
}
