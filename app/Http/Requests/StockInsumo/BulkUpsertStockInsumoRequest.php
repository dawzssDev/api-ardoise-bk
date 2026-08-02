<?php

namespace App\Http\Requests\StockInsumo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpsertStockInsumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('sucursal_id')) {
            $alias = $this->input('sucursalId', $this->input('id_sucursal'));
            if ($alias !== null) {
                $merge['sucursal_id'] = $alias;
            }
        }

        if ($this->exists('items') && is_array($this->input('items'))) {
            $items = [];

            foreach ($this->input('items') as $item) {
                if (! is_array($item)) {
                    $items[] = $item;
                    continue;
                }

                $row = $item;

                if (! array_key_exists('insumo_id', $row)) {
                    $row['insumo_id'] = $row['insumoId'] ?? $row['id_insumo'] ?? null;
                }

                if (! array_key_exists('stock_fisico', $row)) {
                    $row['stock_fisico'] = $row['stockFisico'] ?? $row['stock_actual'] ?? null;
                }

                if (! array_key_exists('stock_minimo', $row)) {
                    $row['stock_minimo'] = $row['stockMinimo'] ?? $row['stockminimo'] ?? null;
                }

                $items[] = $row;
            }

            $merge['items'] = $items;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $negocioId = $this->user()?->negocio?->id;

        return [
            'sucursal_id' => [
                'required',
                'integer',
                Rule::exists('sucursales', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'items' => ['required', 'array', 'min:1'],
            'items.*.insumo_id' => [
                'required',
                'integer',
                Rule::exists('insumos', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'items.*.stock_fisico' => ['required', 'numeric', 'min:0'],
            'items.*.stock_minimo' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sucursal_id.required' => 'La sucursal es obligatoria.',
            'sucursal_id.exists' => 'La sucursal no existe en tu negocio.',
            'items.required' => 'Debes enviar al menos un stock.',
            'items.*.insumo_id.required' => 'El insumo es obligatorio.',
            'items.*.insumo_id.exists' => 'El insumo no existe en tu negocio.',
            'items.*.stock_fisico.required' => 'El stock físico es obligatorio.',
            'items.*.stock_fisico.min' => 'El stock físico no puede ser negativo.',
            'items.*.stock_minimo.required' => 'El stock mínimo es obligatorio.',
            'items.*.stock_minimo.min' => 'El stock mínimo no puede ser negativo.',
        ];
    }
}
