<?php

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNegocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $aliases = [
            'name' => ['nombre_negocio', 'business_name'],
            'phone' => ['telefono'],
            'needs_invoice' => ['necesita_factura'],
            'legal_name' => ['razon_social'],
            'tax_regime' => ['regimen_fiscal'],
            'tax_zip' => ['codigo_postal_fiscal'],
            'cfdi_use' => ['uso_cfdi'],
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
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'phone' => ['sometimes', 'required', 'string', 'max:30'],
            'needs_invoice' => ['sometimes', 'boolean'],
            'rfc' => ['sometimes', 'nullable', 'string', 'max:13'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_regime' => ['sometimes', 'nullable', 'string', 'max:10'],
            'tax_zip' => ['sometimes', 'nullable', 'string', 'max:10'],
            'cfdi_use' => ['sometimes', 'nullable', 'string', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del negocio es obligatorio.',
            'name.max' => 'El nombre del negocio no puede superar :max caracteres.',
            'phone.required' => 'El teléfono es obligatorio.',
            'phone.max' => 'El teléfono no puede superar :max caracteres.',
            'needs_invoice.boolean' => 'El campo de factura debe ser verdadero o falso.',
        ];
    }
}
