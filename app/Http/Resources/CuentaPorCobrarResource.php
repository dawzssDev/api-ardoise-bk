<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CuentaPorCobrarResource extends JsonResource
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
                'name' => $this->sucursal->name,
                'type' => $this->sucursal->type,
            ] : null),
            'empleado_id' => $this->empleado_id,
            'empleado' => $this->whenLoaded('empleado', fn () => $this->empleado ? [
                'id' => $this->empleado->id,
                'full_name' => $this->empleado->fullName(),
                'nombre_completo' => $this->empleado->fullName(),
            ] : null),
            'orden_id' => $this->orden_id,
            'orden' => $this->whenLoaded('orden', fn () => $this->orden ? [
                'id' => $this->orden->id,
                'folio' => $this->orden->numeroOrden(),
                'numero_orden' => $this->orden->numeroOrden(),
                'order_number' => $this->orden->order_number,
            ] : null),
            'orden_detalle_id' => $this->orden_detalle_id,
            'turno_caja_id' => $this->turno_caja_id,
            'concepto' => $this->concepto,
            'monto' => (string) $this->monto,
            'status' => $this->status,
            'fecha_generado' => $this->fecha_generado?->toIso8601String(),
            'fecha_pagado' => $this->fecha_pagado?->toIso8601String(),
            'pagado_por' => $this->pagado_por,
            'nota' => $this->nota,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
