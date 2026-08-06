<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TurnoCajaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_user' => $this->id_user,
            'staff_id' => $this->id_user,
            'user_id' => $this->user_id,
            'cajera' => $this->whenLoaded('cajera', fn () => $this->cajera ? [
                'id' => $this->cajera->id,
                'username' => $this->cajera->username,
                'sucursal_id' => $this->cajera->sucursal_id,
            ] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null),
            'negocio_id' => $this->negocio_id,
            'sucursal_id' => $this->sucursal_id,
            'sucursal' => $this->whenLoaded('sucursal', fn () => $this->sucursal ? [
                'id' => $this->sucursal->id,
                'type' => $this->sucursal->type,
                'name' => $this->sucursal->name,
            ] : null),
            'fondo_inicial' => (string) $this->fondo_inicial,
            'total_ventas_efectivo' => (string) $this->total_ventas_efectivo,
            'total_ventas_tarjeta' => (string) $this->total_ventas_tarjeta,
            'total_ventas_transferencia' => (string) $this->total_ventas_transferencia,
            'total_ventas' => (string) $this->total_ventas,
            'total_pagos_proveedores' => (string) $this->total_pagos_proveedores,
            'total_gastos_operativos' => (string) $this->total_gastos_operativos,
            'efectivo_esperado' => (string) $this->efectivo_esperado,
            'efectivo_real' => $this->efectivo_real !== null ? (string) $this->efectivo_real : null,
            'diferencia' => $this->diferencia !== null ? (string) $this->diferencia : null,
            'status' => $this->status,
            'fecha_apertura' => $this->fecha_apertura?->toIso8601String(),
            'fecha_cierre' => $this->fecha_cierre?->toIso8601String(),
            'observaciones_cierre' => $this->observaciones_cierre,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
