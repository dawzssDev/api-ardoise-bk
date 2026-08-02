<?php

namespace App\Http\Requests\StockInsumo;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStockInsumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

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
        return [
            'stock_fisico' => ['sometimes', 'required', 'numeric', 'min:0'],
            'stock_minimo' => ['sometimes', 'required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'stock_fisico.required' => 'El stock físico es obligatorio.',
            'stock_fisico.numeric' => 'El stock físico debe ser numérico.',
            'stock_fisico.min' => 'El stock físico no puede ser negativo.',
            'stock_minimo.required' => 'El stock mínimo es obligatorio.',
            'stock_minimo.numeric' => 'El stock mínimo debe ser numérico.',
            'stock_minimo.min' => 'El stock mínimo no puede ser negativo.',
        ];
    }
}
