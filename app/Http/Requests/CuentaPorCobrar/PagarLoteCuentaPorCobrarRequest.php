<?php

namespace App\Http\Requests\CuentaPorCobrar;

use Illuminate\Foundation\Http\FormRequest;

class PagarLoteCuentaPorCobrarRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'distinct'],
            'nota' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => 'Debes enviar al menos un id para pagar.',
            'ids.min' => 'Debes enviar al menos un id para pagar.',
        ];
    }
}
