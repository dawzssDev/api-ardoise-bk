<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockProductoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (is_array($this->resource)) {
            return [
                'id' => $this->resource['id'],
                'negocio_id' => $this->resource['negocio_id'],
                'sucursal_id' => $this->resource['sucursal_id'],
                'producto_id' => $this->resource['producto_id'],
                'producto' => $this->resource['producto'],
                'stock_fisico' => $this->resource['stock_fisico'],
                'stock_minimo' => $this->resource['stock_minimo'],
                'is_active' => $this->resource['is_active'],
                'activo' => $this->resource['is_active'],
                'disponible' => $this->resource['is_active'],
                'created_by' => $this->resource['created_by'],
                'updated_by' => $this->resource['updated_by'],
                'created_at' => $this->resource['created_at'],
                'updated_at' => $this->resource['updated_at'],
            ];
        }

        return [
            'id' => $this->id,
            'negocio_id' => $this->negocio_id,
            'sucursal_id' => $this->sucursal_id,
            'sucursal' => $this->whenLoaded('sucursal', fn () => $this->sucursal ? [
                'id' => $this->sucursal->id,
                'type' => $this->sucursal->type,
                'name' => $this->sucursal->name,
            ] : null),
            'producto_id' => $this->producto_id,
            'producto' => $this->whenLoaded('producto', fn () => $this->producto ? [
                'id' => $this->producto->id,
                'name' => $this->producto->name,
                'price' => (string) $this->producto->price,
                'image' => $this->producto->image,
                'image_url' => $this->producto->imageUrl(),
            ] : null),
            'stock_fisico' => (string) $this->stock_fisico,
            'stock_minimo' => (string) $this->stock_minimo,
            'is_active' => (bool) $this->is_active,
            'activo' => (bool) $this->is_active,
            'disponible' => (bool) $this->is_active,
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
}
