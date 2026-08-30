<?php

namespace App\Http\Resources;

use App\Models\OrdenDetalle;
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
            'tipo_venta_id' => $this->tipo_venta_id,
            'tipo_venta' => $this->whenLoaded('tipoVenta', fn () => $this->tipoVenta ? [
                'id' => $this->tipoVenta->id,
                'name' => $this->tipoVenta->name,
                'tipo_descuento' => $this->tipoVenta->tipo_descuento,
                'valor_descuento' => $this->tipoVenta->valor_descuento !== null
                    ? (string) $this->tipoVenta->valor_descuento
                    : null,
                'diferir_cobro' => (bool) $this->tipoVenta->diferir_cobro,
                'requiere_empleado' => (bool) $this->tipoVenta->requiere_empleado,
                'require_autori' => (int) ($this->tipoVenta->require_autori ?? 0),
            ] : null),
            'diferido' => (bool) $this->diferido,
            'empleado_id' => $this->empleado_id,
            'empleado' => $this->whenLoaded('empleado', fn () => $this->empleado ? [
                'id' => $this->empleado->id,
                'full_name' => $this->empleado->fullName(),
                'nombre_completo' => $this->empleado->fullName(),
            ] : null),
            'nombre_pedido' => $this->product_name,
            'product_name' => $this->product_name,
            'cantidad' => (string) $this->quantity,
            'quantity' => (string) $this->quantity,
            'precio_lista' => $this->precio_lista !== null ? (string) $this->precio_lista : null,
            'precio' => (string) $this->price,
            'price' => (string) $this->price,
            'line_total' => $this->lineTotal(),
            'extras' => $this->extras,
            'observaciones' => $this->notes,
            'notes' => $this->notes,
            'estatus' => $this->status,
            'status' => $this->status,
            'status_entregado' => (int) $this->status_entregado,
            'entregado' => (int) $this->status_entregado === OrdenDetalle::ENTREGA_ENTREGADO,
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
