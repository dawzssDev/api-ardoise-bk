<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'user_ardo_vip' => (int) ($this->user_ardo_vip ?? 0),
            'userArdoVIP' => (int) ($this->user_ardo_vip ?? 0),
            'limits' => [
                'sucursales' => $this->limit_sucursales,
                'insumos' => $this->limit_insumos,
                'stock_insumos' => $this->limit_stock_insumos,
                'proveedores' => $this->limit_proveedores,
                'productos' => $this->limit_productos,
                'stock_productos' => $this->limit_stock_productos,
                'personal' => $this->limit_personal,
                'cuentas_contables' => $this->limit_cuentas_contables,
                'roles' => $this->limit_roles,
                'staff' => $this->limit_staff,
            ],
            'limit_sucursales' => $this->limit_sucursales,
            'limit_insumos' => $this->limit_insumos,
            'limit_stock_insumos' => $this->limit_stock_insumos,
            'limit_proveedores' => $this->limit_proveedores,
            'limit_productos' => $this->limit_productos,
            'limit_stock_productos' => $this->limit_stock_productos,
            'limit_personal' => $this->limit_personal,
            'limit_cuentas_contables' => $this->limit_cuentas_contables,
            'limit_roles' => $this->limit_roles,
            'limit_staff' => $this->limit_staff,
            'block_POS' => (int) ($this->block_POS ?? 0),
            'block_pos' => (int) ($this->block_POS ?? 0),
            'negocio' => $this->whenLoaded(
                'negocio',
                fn () => (new NegocioResource($this->negocio))->resolve(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
