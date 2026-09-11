<?php

namespace App\Http\Resources;

use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = (int) $this->status;
        $descuentoStockProd = $this->presentDescuentoStockProd();
        $descuentoStockInsum = $this->presentDescuentoStockInsum();

        return [
            'id' => $this->id,
            'negocio_id' => $this->negocio_id,
            'categoria_producto_id' => $this->categoria_producto_id,
            'categoria' => $this->whenLoaded('categoria', fn () => $this->categoria ? [
                'id' => $this->categoria->id,
                'name' => $this->categoria->name,
                'status' => (int) $this->categoria->status,
            ] : null),
            'name' => $this->name,
            'price' => (string) $this->price,
            'image' => $this->image,
            'image_url' => $this->imageUrl(),
            'descuento_stock_prod' => $descuentoStockProd,
            'descuentoStockProd' => $descuentoStockProd,
            'descuento_stock_insum' => $descuentoStockInsum,
            'descuentoStockInsum' => $descuentoStockInsum,
            'status' => $status,
            'status_label' => $status === Producto::STATUS_ACTIVO ? 'activo' : 'inactivo',
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
     * @return list<array{id: int|null, Producto: string|null, cantidad: mixed}>|null
     */
    private function presentDescuentoStockProd(): ?array
    {
        $items = $this->descuento_stock_prod;
        if (! is_array($items)) {
            return $items;
        }

        return array_values(array_map(static fn (array $item): array => [
            'id' => isset($item['id']) ? (int) $item['id'] : (isset($item['producto_id']) ? (int) $item['producto_id'] : null),
            'Producto' => $item['Producto'] ?? $item['producto'] ?? null,
            'cantidad' => $item['cantidad'] ?? null,
        ], $items));
    }

    /**
     * @return list<array{id: int|null, Insumo: string|null, cantidad: mixed}>|null
     */
    private function presentDescuentoStockInsum(): ?array
    {
        $items = $this->descuento_stock_insum;
        if (! is_array($items)) {
            return $items;
        }

        return array_values(array_map(static fn (array $item): array => [
            'id' => isset($item['id']) ? (int) $item['id'] : (isset($item['insumo_id']) ? (int) $item['insumo_id'] : null),
            'Insumo' => $item['Insumo'] ?? $item['insumo'] ?? null,
            'cantidad' => $item['cantidad'] ?? null,
        ], $items));
    }
}
