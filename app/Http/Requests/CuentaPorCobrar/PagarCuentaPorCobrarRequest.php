<?php

namespace App\Http\Requests\CuentaPorCobrar;

use Illuminate\Foundation\Http\FormRequest;

class PagarCuentaPorCobrarRequest extends FormRequest
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
            'nota' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
