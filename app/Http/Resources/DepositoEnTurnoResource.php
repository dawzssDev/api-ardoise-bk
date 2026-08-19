<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DepositoEnTurnoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'turno_caja_id' => $this->turno_caja_id,
            'id_turno' => $this->turno_caja_id,
            'id_user' => $this->id_user,
            'staff_id' => $this->id_user,
            'id_cajero' => $this->id_user,
            'user_id' => $this->user_id,
            'cajero' => $this->whenLoaded('cajero', fn () => $this->cajero ? [
                'id' => $this->cajero->id,
                'username' => $this->cajero->username,
                'sucursal_id' => $this->cajero->sucursal_id,
            ] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null),
            'negocio_id' => $this->negocio_id,
            'id_empresa' => $this->negocio_id,
            'sucursal_id' => $this->sucursal_id,
            'id_sucursal' => $this->sucursal_id,
            'sucursal' => $this->whenLoaded('sucursal', fn () => $this->sucursal ? [
                'id' => $this->sucursal->id,
                'type' => $this->sucursal->type,
                'name' => $this->sucursal->name,
            ] : null),
            'descripcion' => $this->descripcion,
            'monto' => (string) $this->monto,
            'fecha_registro' => $this->fecha_registro?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
