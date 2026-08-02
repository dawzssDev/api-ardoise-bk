<?php

namespace App\Http\Requests\StockInsumo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertStockInsumoRequest extends FormRequest
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

        if (! $this->exists('insumo_id')) {
            $alias = $this->input('insumoId', $this->input('id_insumo'));
            if ($alias !== null) {
                $merge['insumo_id'] = $alias;
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
            'insumo_id' => [
                'required',
                'integer',
                Rule::exists('insumos', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'stock_fisico' => ['required', 'numeric', 'min:0'],
            'stock_minimo' => ['required', 'numeric', 'min:0'],
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
            'insumo_id.required' => 'El insumo es obligatorio.',
            'insumo_id.exists' => 'El insumo no existe en tu negocio.',
            'stock_fisico.required' => 'El stock físico es obligatorio.',
            'stock_fisico.numeric' => 'El stock físico debe ser numérico.',
            'stock_fisico.min' => 'El stock físico no puede ser negativo.',
            'stock_minimo.required' => 'El stock mínimo es obligatorio.',
            'stock_minimo.numeric' => 'El stock mínimo debe ser numérico.',
            'stock_minimo.min' => 'El stock mínimo no puede ser negativo.',
        ];
    }
}
