<?php

namespace App\Http\Resources;

use App\Models\CategoriaProducto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoriaProductoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = (int) $this->status;

        return [
            'id' => $this->id,
            'negocio_id' => $this->negocio_id,
            'name' => $this->name,
            'status' => $status,
            'status_label' => $status === CategoriaProducto::STATUS_ACTIVO ? 'activo' : 'inactivo',
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
