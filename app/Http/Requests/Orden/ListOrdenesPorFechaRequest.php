<?php

namespace App\Http\Requests\Orden;

use Illuminate\Foundation\Http\FormRequest;

class ListOrdenesPorFechaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->filled('fecha')) {
            foreach (['date', 'dia', 'day'] as $alias) {
                if ($this->filled($alias)) {
                    $merge['fecha'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->filled('sucursal_id')) {
            foreach (['sucursalId', 'SucursalID', 'id_sucursal'] as $alias) {
                if ($this->filled($alias)) {
                    $merge['sucursal_id'] = $this->input($alias);
                    break;
                }
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
            'fecha' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'sucursal_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'fecha.date_format' => 'La fecha debe tener el formato YYYY-MM-DD.',
        ];
    }
}
