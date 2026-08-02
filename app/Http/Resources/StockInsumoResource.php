<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockInsumoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Filas virtuales del listado por sucursal (array)
        if (is_array($this->resource)) {
            return [
                'id' => $this->resource['id'],
                'negocio_id' => $this->resource['negocio_id'],
                'sucursal_id' => $this->resource['sucursal_id'],
                'insumo_id' => $this->resource['insumo_id'],
                'insumo' => $this->resource['insumo'],
                'stock_fisico' => $this->resource['stock_fisico'],
                'stock_minimo' => $this->resource['stock_minimo'],
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
            'insumo_id' => $this->insumo_id,
            'insumo' => $this->whenLoaded('insumo', fn () => $this->insumo ? [
                'id' => $this->insumo->id,
                'name' => $this->insumo->name,
                'status_insumo' => $this->insumo->status_insumo,
            ] : null),
            'stock_fisico' => (string) $this->stock_fisico,
            'stock_minimo' => (string) $this->stock_minimo,
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
