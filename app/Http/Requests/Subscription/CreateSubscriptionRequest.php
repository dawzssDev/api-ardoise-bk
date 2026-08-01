<?php

namespace App\Http\Requests\Subscription;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSubscriptionRequest extends FormRequest
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
            // plan: prueba|mensual|anual  OR  plan_id: price_xxx (lo que envía el front)
            'plan' => ['required_without:plan_id', 'nullable', 'string', Rule::in(['prueba', 'mensual', 'anual'])],
            'plan_id' => ['required_without:plan', 'nullable', 'string', 'starts_with:price_'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan.required' => 'El plan es obligatorio.',
            'plan.required_without' => 'El plan es obligatorio si no envías plan_id.',
            'plan.string' => 'El plan debe ser texto.',
            'plan.in' => 'El plan debe ser: prueba, mensual o anual.',
            'plan_id.required_without' => 'El plan_id es obligatorio si no envías plan.',
            'plan_id.starts_with' => 'El plan_id debe ser un price_id válido de Stripe.',
        ];
    }
}
