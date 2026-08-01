<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [
            'name' => $this->firstPresent(['name', 'nombre', 'nombre_completo', 'fullName']),
            'business_name' => $this->firstPresent(['business_name', 'businessName', 'nombre_negocio']),
            'phone' => $this->firstPresent(['phone', 'telefono', 'phoneNumber']),
            'legal_name' => $this->firstPresent(['legal_name', 'legalName', 'razon_social']),
            'tax_regime' => $this->firstPresent(['tax_regime', 'taxRegime', 'regimen_fiscal']),
            'tax_zip' => $this->firstPresent(['tax_zip', 'taxZip', 'codigo_postal_fiscal']),
            'cfdi_use' => $this->firstPresent(['cfdi_use', 'cfdiUse', 'uso_cfdi']),
            // Toggle apagado suele llegar como "", null o "false"
            'needs_invoice' => $this->toBoolean(
                $this->firstPresent(['needs_invoice', 'needsInvoice', 'necesita_factura']),
                default: false,
            ),
            // Checkbox: true | 1 | "true" | "on" | "yes" | alias en español/camelCase
            'terms_accepted' => $this->toBoolean(
                $this->firstPresent([
                    'terms_accepted',
                    'termsAccepted',
                    'acepto_terminos',
                    'accept_terms',
                    'acceptTerms',
                    'terms',
                ]),
                default: false,
            ),
        ];

        $this->merge(array_filter(
            $merge,
            static fn ($value) => $value !== null,
        ) + [
            // Siempre normalizar estos booleanos (también si son false)
            'needs_invoice' => $merge['needs_invoice'],
            'terms_accepted' => $merge['terms_accepted'] ? true : false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'business_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['required', 'string', 'max:30'],
            'password' => ['required', 'confirmed', 'min:8'],
            'terms_accepted' => ['accepted'],
            'needs_invoice' => ['sometimes', 'boolean'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'tax_regime' => ['nullable', 'string', 'max:10'],
            'tax_zip' => ['nullable', 'string', 'max:10'],
            'cfdi_use' => ['nullable', 'string', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede superar :max caracteres.',
            'business_name.required' => 'El nombre del negocio es obligatorio.',
            'business_name.max' => 'El nombre del negocio no puede superar :max caracteres.',
            'email.required' => 'El correo es obligatorio.',
            'email.email' => 'El correo no es válido.',
            'email.unique' => 'El correo ya está registrado.',
            'phone.required' => 'El teléfono es obligatorio.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
            'password.min' => 'La contraseña debe tener al menos :min caracteres.',
            'terms_accepted.accepted' => 'Debes aceptar los Términos y Condiciones y el Aviso de Privacidad.',
            'needs_invoice.boolean' => 'El campo de factura debe ser verdadero o falso.',
        ];
    }

    /**
     * @param  list<string>  $keys
     */
    private function firstPresent(array $keys): mixed
    {
        foreach ($keys as $key) {
            if ($this->exists($key)) {
                return $this->input($key);
            }
        }

        return null;
    }

    private function toBoolean(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $normalized ?? $default;
    }
}
