<?php

namespace App\Services;

use App\Models\Empleado;
use App\Models\Negocio;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EmpleadoService
{
    private const IMAGE_DISK = 'empleados';

    public function negocioForUser(User $user): Negocio
    {
        $negocio = $user->negocio;

        if (! $negocio) {
            throw new HttpException(422, 'El usuario no tiene un negocio asociado.');
        }

        return $negocio;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Negocio $negocio, User $user, array $data): Empleado
    {
        $imagePath = null;
        if (($data['image'] ?? null) instanceof UploadedFile) {
            $imagePath = $this->storeImage($negocio, $data['image']);
        }

        return $negocio->empleados()->create([
            'sucursal_id' => $data['sucursal_id'],
            'role_id' => $data['role_id'],
            'first_name' => $data['first_name'],
            'paternal_surname' => $data['paternal_surname'],
            'maternal_surname' => $data['maternal_surname'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'gender' => $data['gender'] ?? null,
            'curp' => $data['curp'] ?? null,
            'rfc' => $data['rfc'] ?? null,
            'nss' => $data['nss'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'employee_number' => $data['employee_number'],
            'supervisor_name' => $data['supervisor_name'] ?? null,
            'hire_date' => $data['hire_date'] ?? null,
            'contract_type' => $data['contract_type'] ?? null,
            'shift' => $data['shift'] ?? null,
            'status' => $data['status'] ?? 'activo',
            'salary' => $data['salary'] ?? null,
            'salary_frequency' => $data['salary_frequency'] ?? null,
            'image' => $imagePath,
            'emergency_contact_name' => $data['emergency_contact_name'] ?? null,
            'emergency_contact_relationship' => $data['emergency_contact_relationship'] ?? null,
            'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    public function listForNegocio(Negocio $negocio, int $perPage = 15): LengthAwarePaginator
    {
        return $negocio->empleados()
            ->with([
                'sucursal:id,negocio_id,type,name',
                'role:id,negocio_id,name,status',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->latest()
            ->paginate($perPage);
    }

    public function findForNegocio(Negocio $negocio, int $empleadoId): Empleado
    {
        return $negocio->empleados()
            ->with([
                'sucursal:id,negocio_id,type,name',
                'role:id,negocio_id,name,status',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->findOrFail($empleadoId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Empleado $empleado, User $user, array $data): Empleado
    {
        if (($data['image'] ?? null) instanceof UploadedFile) {
            $this->deleteImage($empleado->image);
            $data['image'] = $this->storeImage($empleado->negocio, $data['image']);
        } else {
            unset($data['image']);
        }

        $empleado->fill($data);
        $empleado->updated_by = $user->id;
        $empleado->save();

        return $empleado->refresh()->load([
            'sucursal:id,negocio_id,type,name',
            'role:id,negocio_id,name,status',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    public function setStatus(Empleado $empleado, User $user, string $status): Empleado
    {
        $empleado->status = $status;
        $empleado->updated_by = $user->id;
        $empleado->save();

        return $empleado->refresh()->load([
            'sucursal:id,negocio_id,type,name',
            'role:id,negocio_id,name,status',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    public function delete(Empleado $empleado): void
    {
        $this->deleteImage($empleado->image);
        $empleado->delete();
    }

    private function storeImage(Negocio $negocio, UploadedFile $file): string
    {
        return $file->store((string) $negocio->id, self::IMAGE_DISK);
    }

    private function deleteImage(?string $path): void
    {
        if (! $path) {
            return;
        }

        if (Storage::disk(self::IMAGE_DISK)->exists($path)) {
            Storage::disk(self::IMAGE_DISK)->delete($path);
        }
    }
}
