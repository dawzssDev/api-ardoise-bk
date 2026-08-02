<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('usuario')) {
            foreach (['username', 'user', 'login'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['usuario'] = $this->input($alias);
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
            // Maestro: email. Staff: usuario (también puede ir en email si el front reutiliza el campo).
            'email' => ['required_without:usuario', 'nullable', 'string', 'max:255'],
            'usuario' => ['required_without:email', 'nullable', 'string', 'max:100'],
            'password' => ['required', 'string'],
        ];
    }

    public function loginIdentifier(): string
    {
        return (string) ($this->validated('email') ?? $this->validated('usuario'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required_without' => 'El correo o usuario es obligatorio.',
            'usuario.required_without' => 'El correo o usuario es obligatorio.',
            'password.required' => 'La contraseña es obligatoria.',
            'password.string' => 'La contraseña debe ser texto.',
        ];
    }
}
