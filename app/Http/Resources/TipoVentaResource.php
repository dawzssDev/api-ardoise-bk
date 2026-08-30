<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TipoVentaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'negocio_id' => $this->negocio_id,
            'name' => $this->name,
            'tipo_descuento' => $this->tipo_descuento,
            'valor_descuento' => $this->valor_descuento !== null
                ? (string) $this->valor_descuento
                : null,
            'diferir_cobro' => (bool) $this->diferir_cobro,
            'requiere_empleado' => (bool) $this->requiere_empleado,
            'require_autori' => (int) ($this->require_autori ?? 0),
            'status' => (bool) $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
