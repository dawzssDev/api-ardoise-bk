<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentIntentRequest extends FormRequest
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
            // Pago único: amount en centavos
            // Suscripción (front actual): plan_id = price_xxx de Stripe
            'amount' => ['required_without:plan_id', 'nullable', 'integer', 'min:1'],
            'plan_id' => ['required_without:amount', 'nullable', 'string', 'starts_with:price_'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'El monto es obligatorio.',
            'amount.required_without' => 'El monto es obligatorio si no envías plan_id.',
            'amount.integer' => 'El monto debe ser un entero en centavos.',
            'amount.min' => 'El monto debe ser al menos :min centavo.',
            'plan_id.required_without' => 'El plan_id es obligatorio si no envías amount.',
            'plan_id.regex' => 'El plan_id debe ser un price_id válido de Stripe.',
            'currency.string' => 'La moneda debe ser texto.',
            'currency.size' => 'La moneda debe tener 3 caracteres (ISO).',
        ];
    }
}
