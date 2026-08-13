<?php

namespace App\Http\Requests\StockProducto;

use App\Models\StockProducto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpsertStockProductoRequest extends FormRequest
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

                if (! array_key_exists('producto_id', $row)) {
                    $row['producto_id'] = $row['productoId'] ?? $row['id_producto'] ?? null;
                }

                if (! array_key_exists('stock_fisico', $row)) {
                    $row['stock_fisico'] = $row['stockFisico'] ?? $row['stock_actual'] ?? null;
                }

                if (! array_key_exists('stock_minimo', $row)) {
                    $row['stock_minimo'] = $row['stockMinimo'] ?? $row['stockminimo'] ?? null;
                }

                if (! array_key_exists('is_active', $row)) {
                    $row['is_active'] = $row['activo'] ?? $row['disponible'] ?? $row['status'] ?? $row['active'] ?? null;
                }

                $normalizedActivo = StockProducto::normalizeActivo($row['is_active'] ?? null);
                if ($normalizedActivo !== null) {
                    $row['is_active'] = $normalizedActivo;
                } else {
                    unset($row['is_active']);
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
            'items.*.producto_id' => [
                'required',
                'integer',
                Rule::exists('productos', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'items.*.stock_fisico' => ['required', 'numeric', 'min:0'],
            'items.*.stock_minimo' => ['required', 'numeric', 'min:0'],
            'items.*.is_active' => ['sometimes', 'boolean'],
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
            'items.*.producto_id.required' => 'El producto es obligatorio.',
            'items.*.producto_id.exists' => 'El producto no existe en tu negocio.',
            'items.*.stock_fisico.required' => 'El stock físico es obligatorio.',
            'items.*.stock_fisico.min' => 'El stock físico no puede ser negativo.',
            'items.*.stock_minimo.required' => 'El stock mínimo es obligatorio.',
            'items.*.stock_minimo.min' => 'El stock mínimo no puede ser negativo.',
            'items.*.is_active.boolean' => 'El campo activo debe ser verdadero o falso.',
        ];
    }
}
