<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmpleadoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'negocio_id' => $this->negocio_id,
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
                'status' => $this->role->status,
            ] : null),
            'first_name' => $this->first_name,
            'paternal_surname' => $this->paternal_surname,
            'maternal_surname' => $this->maternal_surname,
            'full_name' => $this->fullName(),
            'birth_date' => $this->birth_date?->format('Y-m-d'),
            'gender' => $this->gender,
            'curp' => $this->curp,
            'rfc' => $this->rfc,
            'nss' => $this->nss,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'employee_number' => $this->employee_number,
            'supervisor_name' => $this->supervisor_name,
            'hire_date' => $this->hire_date?->format('Y-m-d'),
            'contract_type' => $this->contract_type,
            'shift' => $this->shift,
            'status' => $this->status,
            'salary' => $this->salary !== null ? (string) $this->salary : null,
            'salary_frequency' => $this->salary_frequency,
            'image' => $this->image,
            'image_url' => $this->imageUrl(),
            'emergency_contact_name' => $this->emergency_contact_name,
            'emergency_contact_relationship' => $this->emergency_contact_relationship,
            'emergency_contact_phone' => $this->emergency_contact_phone,
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
