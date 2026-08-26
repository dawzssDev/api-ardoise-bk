<?php

namespace App\Http\Resources;

use App\Models\MaeCuentaContaSuc;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaeCuentaContaSucResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = (int) $this->status;
        $deleted = (int) $this->deleted;

        return [
            'id' => $this->id,
            'negocio_id' => $this->negocio_id,
            'negocioID' => $this->negocio_id,
            'tipo_cuenta' => $this->tipo_cuenta,
            'tipoCuenta' => $this->tipo_cuenta,
            'sucursal_id' => $this->sucursal_id,
            'SucursaliD' => $this->sucursal_id,
            'sucursal' => $this->whenLoaded('sucursal', fn () => $this->sucursal ? [
                'id' => $this->sucursal->id,
                'type' => $this->sucursal->type,
                'name' => $this->sucursal->name,
            ] : null),
            'titulo_cuenta' => $this->titulo_cuenta,
            'tituloCuenta' => $this->titulo_cuenta,
            'descripcion_cuenta' => $this->descripcion_cuenta,
            'descripcionCuenta' => $this->descripcion_cuenta,
            'status' => $status,
            'status_label' => $status === MaeCuentaContaSuc::STATUS_ACTIVO ? 'activo' : 'inactivo',
            'deleted' => $deleted,
            'delete' => $deleted,
            'userCreation' => $this->created_by,
            'userUpdate' => $this->updated_by,
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
            'dateCreation' => $this->created_at?->toIso8601String(),
            'dateUpdate' => $this->updated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
