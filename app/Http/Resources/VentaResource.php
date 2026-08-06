<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VentaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'turno_caja_id' => $this->turno_caja_id,
            'id_user' => $this->id_user,
            'staff_id' => $this->id_user,
            'cajera' => $this->whenLoaded('cajera', fn () => $this->cajera ? [
                'id' => $this->cajera->id,
                'username' => $this->cajera->username,
            ] : null),
            'orden_id' => $this->orden_id,
            'order_number' => $this->order_number,
            'numero_orden' => str_pad((string) $this->order_number, 6, '0', STR_PAD_LEFT),
            'payment_type' => $this->payment_type,
            'tipo_pago' => $this->payment_type,
            'total' => (string) $this->total,
            'sucursal_id' => $this->sucursal_id,
            'negocio_id' => $this->negocio_id,
            'fecha_venta' => $this->fecha_venta?->toIso8601String(),
            'fechaVenta' => $this->fecha_venta?->toIso8601String(),
            'orden' => $this->whenLoaded('orden', fn () => $this->orden ? [
                'id' => $this->orden->id,
                'order_number' => $this->orden->order_number,
                'customer_name' => $this->orden->customer_name,
                'status' => $this->orden->status,
                'total' => (string) $this->orden->total,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
