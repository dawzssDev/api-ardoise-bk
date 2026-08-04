<?php

namespace App\Http\Resources;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero_orden' => $this->numeroOrden(),
            'order_number' => $this->order_number,
            'negocio_id' => $this->negocio_id,
            'sucursal_id' => $this->sucursal_id,
            'sucursal' => $this->whenLoaded('sucursal', fn () => $this->sucursal ? [
                'id' => $this->sucursal->id,
                'type' => $this->sucursal->type,
                'name' => $this->sucursal->name,
            ] : null),
            'nombre_cliente' => $this->customer_name,
            'customer_name' => $this->customer_name,
            'tipo_pago' => $this->payment_type,
            'payment_type' => $this->payment_type,
            'total_pago' => (string) $this->total,
            'total' => (string) $this->total,
            'estatus' => $this->status,
            'status' => $this->status,
            'detalles' => OrdenDetalleResource::collection($this->whenLoaded('detalles')),
            'staff_creo' => $this->whenLoaded('createdByStaff', fn () => $this->staffPayload($this->createdByStaff)),
            'staff_avanzo' => $this->whenLoaded('advancedByStaff', fn () => $this->staffPayload($this->advancedByStaff)),
            'staff_finalizo' => $this->whenLoaded('finishedByStaff', fn () => $this->staffPayload($this->finishedByStaff)),
            'created_by_staff_id' => $this->created_by_staff_id,
            'advanced_by_staff_id' => $this->advanced_by_staff_id,
            'finished_by_staff_id' => $this->finished_by_staff_id,
            'advanced_at' => $this->advanced_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
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
     * @return array{id: int, username: string}|null
     */
    private function staffPayload(?Staff $staff): ?array
    {
        if (! $staff) {
            return null;
        }

        return [
            'id' => $staff->id,
            'username' => $staff->username,
        ];
    }
}
