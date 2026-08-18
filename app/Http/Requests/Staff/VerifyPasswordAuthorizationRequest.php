<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPasswordAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('password_authorization')) {
            foreach (['pin', 'passwordAuthorization', 'password_autorizacion', 'pin_autorizacion', 'pinAutorizacion'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['password_authorization'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('staff_ids')) {
            foreach (['staffIds', 'ids'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['staff_ids'] = $this->input($alias);
                    break;
                }
            }
        }

        $pin = $merge['password_authorization'] ?? $this->input('password_authorization');
        if ($pin !== null && $pin !== '') {
            $merge['password_authorization'] = (string) $pin;
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
            'password_authorization' => ['required', 'digits:6'],
            'staff_ids' => ['required', 'array', 'min:1'],
            'staff_ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password_authorization.required' => 'La contraseña de autorización es obligatoria.',
            'password_authorization.digits' => 'La contraseña de autorización debe tener exactamente 6 dígitos.',
            'staff_ids.required' => 'Debes indicar los usuarios staff a validar.',
            'staff_ids.array' => 'Los usuarios staff deben enviarse en una lista.',
            'staff_ids.min' => 'Debes indicar al menos un usuario staff.',
            'staff_ids.*.integer' => 'Cada usuario staff debe ser un identificador numérico.',
            'staff_ids.*.distinct' => 'Los usuarios staff no deben repetirse.',
        ];
    }
}
