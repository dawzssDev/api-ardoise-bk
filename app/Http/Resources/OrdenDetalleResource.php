<?php

namespace App\Http\Resources;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdenDetalleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orden_id' => $this->orden_id,
            'producto_id' => $this->producto_id,
            'producto' => $this->whenLoaded('producto', fn () => $this->producto ? [
                'id' => $this->producto->id,
                'name' => $this->producto->name,
                'price' => (string) $this->producto->price,
            ] : null),
            'nombre_pedido' => $this->product_name,
            'product_name' => $this->product_name,
            'cantidad' => (string) $this->quantity,
            'quantity' => (string) $this->quantity,
            'precio' => (string) $this->price,
            'price' => (string) $this->price,
            'line_total' => $this->lineTotal(),
            'extras' => $this->extras,
            'observaciones' => $this->notes,
            'notes' => $this->notes,
            'estatus' => $this->status,
            'status' => $this->status,
            'staff_avanzo' => $this->whenLoaded('advancedByStaff', fn () => $this->staffPayload($this->advancedByStaff)),
            'staff_finalizo' => $this->whenLoaded('finishedByStaff', fn () => $this->staffPayload($this->finishedByStaff)),
            'advanced_by_staff_id' => $this->advanced_by_staff_id,
            'finished_by_staff_id' => $this->finished_by_staff_id,
            'advanced_at' => $this->advanced_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
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
