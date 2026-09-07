<?php

namespace App\Http\Requests\Sucursal;

use App\Models\Sucursal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSucursalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $aliases = [
            'monto_maximo_efectivo' => [
                'montoMaximoEfectivo',
                'maximo_efectivo',
                'maximoEfectivo',
                'monto_maximo_caja',
                'montoMaximoCaja',
            ],
        ];

        $merge = [];

        foreach ($aliases as $field => $keys) {
            if ($this->exists($field)) {
                continue;
            }

            foreach ($keys as $alias) {
                if ($this->exists($alias)) {
                    $merge[$field] = $this->input($alias);
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
            'type' => ['required', 'string', Rule::in(Sucursal::TYPES)],
            'name' => ['required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            'street' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'opened_year' => ['nullable', 'integer', 'min:1900', 'max:'.((int) date('Y') + 1)],
            'monto_maximo_efectivo' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'El tipo es obligatorio.',
            'type.in' => 'El tipo debe ser sucursal o bodega.',
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede superar :max caracteres.',
            'is_active.boolean' => 'El campo activa debe ser verdadero o falso.',
            'opened_year.integer' => 'El año de apertura debe ser un número.',
            'opened_year.min' => 'El año de apertura no es válido.',
            'opened_year.max' => 'El año de apertura no es válido.',
            'monto_maximo_efectivo.numeric' => 'El monto máximo de efectivo debe ser un número.',
            'monto_maximo_efectivo.min' => 'El monto máximo de efectivo no puede ser negativo.',
        ];
    }
}
