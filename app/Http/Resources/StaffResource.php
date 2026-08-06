<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'negocio_id' => $this->negocio_id,
            'username' => $this->username,
            'sucursal_id' => $this->sucursal_id,
            'sucursal' => $this->whenLoaded('sucursal', fn () => $this->sucursal ? [
                'id' => $this->sucursal->id,
                'type' => $this->sucursal->type,
                'name' => $this->sucursal->name,
            ] : null),
            'role_id' => $this->role_id,
            'role' => $this->whenLoaded('role', fn () => $this->role ? [
                'id' => $this->role->id,
                'name' => $this->role->name,
                'permissions' => \App\Models\Role::normalizePermissions(
                    is_array($this->role->permissions) ? $this->role->permissions : null
                ),
                'status' => $this->role->status,
            ] : null),
            'empleado_id' => $this->empleado_id,
            'empleado' => $this->whenLoaded('empleado', fn () => $this->empleado ? [
                'id' => $this->empleado->id,
                'employee_number' => $this->empleado->employee_number,
                'first_name' => $this->empleado->first_name,
                'paternal_surname' => $this->empleado->paternal_surname,
                'maternal_surname' => $this->empleado->maternal_surname,
                'full_name' => $this->empleado->fullName(),
                'status' => $this->empleado->status,
            ] : null),
            'status' => $this->status,
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
                'email' => $this->createdBy?->email,
            ]),
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy ? [
                'id' => $this->updatedBy->id,
                'name' => $this->updatedBy->name,
                'email' => $this->updatedBy->email,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
