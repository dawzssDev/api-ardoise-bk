<?php

namespace App\Http\Requests\Sucursal;

use Illuminate\Foundation\Http\FormRequest;

class ToggleSucursalActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('is_active') && $this->exists('activa')) {
            $this->merge(['is_active' => $this->input('activa')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_active.required' => 'Debes indicar si la sucursal está activa o no.',
            'is_active.boolean' => 'El campo activa debe ser verdadero o falso.',
        ];
    }
}
