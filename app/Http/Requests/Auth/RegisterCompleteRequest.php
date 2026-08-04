<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('registration_token')) {
            foreach (['token', 'registrationToken'] as $alias) {
                if ($this->exists($alias)) {
                    $this->merge(['registration_token' => $this->input($alias)]);
                    break;
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'registration_token' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'registration_token.required' => 'El token de registro es obligatorio.',
            'registration_token.uuid' => 'El token de registro no es válido.',
        ];
    }
}
