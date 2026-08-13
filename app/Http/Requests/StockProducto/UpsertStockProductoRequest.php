<?php

namespace App\Http\Requests\StockProducto;

use App\Models\StockProducto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertStockProductoRequest extends FormRequest
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

        if (! $this->exists('producto_id')) {
            $alias = $this->input('productoId', $this->input('id_producto'));
            if ($alias !== null) {
                $merge['producto_id'] = $alias;
            }
        }

        if (! $this->exists('stock_fisico')) {
            $alias = $this->input('stockFisico', $this->input('stock_actual'));
            if ($alias !== null) {
                $merge['stock_fisico'] = $alias;
            }
        }

        if (! $this->exists('stock_minimo')) {
            $alias = $this->input('stockMinimo', $this->input('stockminimo'));
            if ($alias !== null) {
                $merge['stock_minimo'] = $alias;
            }
        }

        if (! $this->exists('is_active')) {
            foreach (['activo', 'activa', 'disponible', 'status', 'active'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['is_active'] = $this->input($alias);
                    break;
                }
            }
        }

        $rawActivo = $merge['is_active'] ?? $this->input('is_active');
        $normalizedActivo = StockProducto::normalizeActivo($rawActivo);
        if ($normalizedActivo !== null) {
            $merge['is_active'] = $normalizedActivo;
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
            'producto_id' => [
                'required',
                'integer',
                Rule::exists('productos', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'stock_fisico' => ['required', 'numeric', 'min:0'],
            'stock_minimo' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
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
            'producto_id.required' => 'El producto es obligatorio.',
            'producto_id.exists' => 'El producto no existe en tu negocio.',
            'stock_fisico.required' => 'El stock físico es obligatorio.',
            'stock_fisico.numeric' => 'El stock físico debe ser numérico.',
            'stock_fisico.min' => 'El stock físico no puede ser negativo.',
            'stock_minimo.required' => 'El stock mínimo es obligatorio.',
            'stock_minimo.numeric' => 'El stock mínimo debe ser numérico.',
            'stock_minimo.min' => 'El stock mínimo no puede ser negativo.',
            'is_active.boolean' => 'El campo activo debe ser verdadero o falso.',
        ];
    }
}
