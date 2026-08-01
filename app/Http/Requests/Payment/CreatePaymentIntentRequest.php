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
            'amount' => ['required', 'integer', 'min:1'],
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
            'amount.integer' => 'El monto debe ser un entero en centavos.',
            'amount.min' => 'El monto debe ser al menos :min centavo.',
            'currency.string' => 'La moneda debe ser texto.',
            'currency.size' => 'La moneda debe tener 3 caracteres (ISO).',
        ];
    }
}
