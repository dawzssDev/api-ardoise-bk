<?php

namespace App\Http\Requests\Insumo;

use Illuminate\Foundation\Http\FormRequest;

class ToggleInsumoStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('status_insumo') && $this->exists('status')) {
            $this->merge(['status_insumo' => $this->input('status')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status_insumo' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status_insumo.required' => 'Debes indicar el status del insumo.',
            'status_insumo.boolean' => 'El status del insumo debe ser verdadero o falso.',
        ];
    }
}
