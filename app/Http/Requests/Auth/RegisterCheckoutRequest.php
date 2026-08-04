<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('registration_token')) {
            foreach (['token', 'registrationToken'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['registration_token'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('plan_id')) {
            foreach (['planId', 'price_id', 'priceId'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['plan_id'] = $this->input($alias);
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
            'registration_token' => ['required', 'uuid'],
            'plan_id' => ['required_without:plan', 'nullable', 'string', 'starts_with:price_'],
            'plan' => ['required_without:plan_id', 'nullable', 'string', 'in:prueba,mensual,anual'],
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
            'plan_id.required_without' => 'El plan o plan_id es obligatorio.',
            'plan.required_without' => 'El plan o plan_id es obligatorio.',
            'plan.in' => 'El plan debe ser: prueba, mensual o anual.',
        ];
    }
}
