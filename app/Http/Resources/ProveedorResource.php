<?php

namespace App\Http\Resources;

use App\Models\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProveedorResource extends JsonResource
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
            'legal_name' => $this->legal_name,
            'rfc' => $this->rfc,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'contact_name' => $this->contact_name,
            'notes' => $this->notes,
            'dp_Folio' => $this->dp_Folio,
            'dp_Categoria' => $this->dp_Categoria,
            'cce_momento_pedido' => $this->cce_momento_pedido,
            'cce_dias_entrega' => $this->cce_dias_entrega,
            'cce_envioDom_costo' => $this->cce_envioDom_costo,
            'cce_forma_pago' => $this->cce_forma_pago,
            'cce_condiciones_pagos' => $this->cce_condiciones_pagos,
            'cce_solicitar_factura' => $this->cce_solicitar_factura,
            'cce_pedido_min' => $this->cce_pedido_min,
            'cce_descansos' => $this->cce_descansos,
            'cce_tiempo_entrega' => $this->cce_tiempo_entrega,
            'cce_descuento_pVolumen' => $this->cce_descuento_pVolumen,
            'cce_lugar_entrega' => $this->cce_lugar_entrega,
            'cce_frecuencia_pedido' => $this->cce_frecuencia_pedido,
            'cce_NoTarjetaClave' => $this->cce_NoTarjetaClave,
            'cce_banco' => $this->cce_banco,
            'cce_propietario' => $this->cce_propietario,
            'InsumosPrecios' => $this->presentInsumosPrecios(),
            'Incidencias' => $this->Incidencias,
            'status' => $status,
            'status_label' => $status === Proveedor::STATUS_ACTIVO ? 'activo' : 'baja',
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

    /**
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    private function presentInsumosPrecios(): mixed
    {
        $value = $this->InsumosPrecios;
        if ($value === null || $value === '') {
            return null;
        }

        return Proveedor::normalizeInsumosPrecios($value);
    }
}
