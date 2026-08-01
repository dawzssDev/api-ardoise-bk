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
        ];
    }
}
