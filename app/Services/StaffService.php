<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Staff;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StaffService
{
    use ResolvesNegocioFromActor;

    /**
     * @param  array{
     *     username: string,
     *     password: string,
     *     password_authorization?: string|null,
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
            'password_authorization' => $this->plainPinOrNull($data['password_authorization'] ?? null),
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

        if (array_key_exists('password_authorization', $data)) {
            $data['password_authorization'] = $this->plainPinOrNull($data['password_authorization']);
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
     * Indica si la sucursal del actor tiene staff con PIN de autorización.
     *
     * Staff: siempre se usa su sucursal de sesión.
     * Maestro: debe indicar sucursal_id.
     *
     * @return array{has_password_authorization: 0|1, staff_ids: list<int>}
     */
    public function passwordAuthorizationForActor(User|Staff $actor, ?int $sucursalId = null): array
    {
        $negocio = $this->negocioForUser($actor);

        if ($actor instanceof Staff) {
            $sucursalId = (int) $actor->sucursal_id;
        }

        if (! $sucursalId) {
            throw new HttpException(422, 'Se requiere una sucursal para consultar la autorización.');
        }

        $sucursalOk = $negocio->sucursales()->whereKey($sucursalId)->exists();
        if (! $sucursalOk) {
            throw new HttpException(422, 'La sucursal no pertenece a tu negocio.');
        }

        $staffIds = $negocio->staff()
            ->where('sucursal_id', $sucursalId)
            ->whereNotNull('password_authorization')
            ->where('status', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($staffIds === []) {
            return [
                'has_password_authorization' => 0,
                'staff_ids' => [],
            ];
        }

        return [
            'has_password_authorization' => 1,
            'staff_ids' => $staffIds,
        ];
    }

    /**
     * Valida que el PIN coincida con alguno de los staff_ids de la sucursal/negocio de sesión.
     *
     * @param  list<int>  $staffIds
     */
    public function verifyPasswordAuthorization(User|Staff $actor, int|string $pin, array $staffIds): Staff
    {
        $negocio = $this->negocioForUser($actor);

        $query = $negocio->staff()
            ->whereIn('id', $staffIds)
            ->whereNotNull('password_authorization')
            ->where('status', true);

        if ($actor instanceof Staff) {
            $query->where('sucursal_id', (int) $actor->sucursal_id);
        }

        $plain = (string) $pin;

        foreach ($query->orderBy('id')->get(['id', 'password_authorization']) as $staff) {
            if (is_string($staff->password_authorization) && Hash::check($plain, $staff->password_authorization)) {
                return $staff;
            }
        }

        throw new HttpException(401, 'La contraseña de autorización no es válida.');
    }

    private function plainPinOrNull(mixed $pin): ?string
    {
        if ($pin === null || $pin === '') {
            return null;
        }

        return (string) $pin;
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
