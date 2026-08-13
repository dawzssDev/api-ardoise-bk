<?php

namespace App\Http\Requests\StockInsumo;

use App\Models\StockInsumo;
use Illuminate\Foundation\Http\FormRequest;

class ToggleStockInsumoActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('is_active')) {
            foreach (['activo', 'activa', 'disponible', 'status', 'active'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['is_active'] = $this->input($alias);
                    break;
                }
            }
        }

        $raw = $merge['is_active'] ?? $this->input('is_active');
        $normalized = StockInsumo::normalizeActivo($raw);
        if ($normalized !== null) {
            $merge['is_active'] = $normalized;
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
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_active.required' => 'Debes indicar si el insumo está activo en la sucursal.',
            'is_active.boolean' => 'El campo activo debe ser verdadero o falso.',
        ];
    }
}
