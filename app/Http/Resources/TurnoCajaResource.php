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
            'total_retiros_efectivo' => (string) $this->total_retiros_efectivo,
            'total_depositos_efectivo' => (string) $this->total_depositos_efectivo,
            'efectivo_esperado' => (string) $this->efectivo_esperado,
            'efectivo_real' => $this->efectivo_real !== null ? (string) $this->efectivo_real : null,
            'efectivo_real_cajera' => $this->efectivo_real_cajera !== null
                ? (string) $this->efectivo_real_cajera
                : null,
            'diferencia' => $this->diferencia !== null ? (string) $this->diferencia : null,
            'status' => $this->status,
            'status_administrador' => $this->status_administrador,
            'statusAdministrador' => $this->status_administrador,
            'status_gerencia' => $this->status_gerencia,
            'statusGerencia' => $this->status_gerencia,
            'efectivo_gerencia' => $this->efectivo_gerencia !== null ? (string) $this->efectivo_gerencia : null,
            'terminal_gerencia' => $this->terminal_gerencia !== null ? (string) $this->terminal_gerencia : null,
            'diferencia_gerencia' => $this->diferencia_gerencia !== null ? (string) $this->diferencia_gerencia : null,
            'diferenciaGerencia' => $this->diferencia_gerencia !== null ? (string) $this->diferencia_gerencia : null,
            'direfencia_Gerencia' => $this->diferencia_gerencia !== null ? (string) $this->diferencia_gerencia : null,
            'date_validation_gerencia' => $this->date_validation_gerencia?->toIso8601String(),
            'dateValidationGerencia' => $this->date_validation_gerencia?->toIso8601String(),
            'user_id_date_validation_gerencia' => $this->user_id_date_validation_gerencia,
            'user_id_dateValidationGerencia' => $this->user_id_date_validation_gerencia,
            'validado_por_gerencia' => $this->whenLoaded('validadoPorGerencia', fn () => $this->validadoPorGerencia ? [
                'id' => $this->validadoPorGerencia->id,
                'name' => $this->validadoPorGerencia->name,
                'email' => $this->validadoPorGerencia->email,
            ] : null),
            'fecha_apertura' => $this->fecha_apertura?->toIso8601String(),
            'fecha_cierre' => $this->fecha_cierre?->toIso8601String(),
            'fecha_cierre_cajera' => $this->fecha_cierre_cajera?->toIso8601String(),
            'observaciones_cierre' => $this->observaciones_cierre,
            'cortes' => $this->whenLoaded(
                'cortes',
                fn () => TurnoCajaCorteResource::collection($this->cortes)->resolve(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
