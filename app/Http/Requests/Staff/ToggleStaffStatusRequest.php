<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;

class ToggleStaffStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('status') && $this->exists('activo')) {
            $this->merge(['status' => $this->input('activo')]);
        } elseif (! $this->exists('status') && $this->exists('estatus')) {
            $this->merge(['status' => $this->input('estatus')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Debes indicar el status del staff.',
            'status.boolean' => 'El status debe ser verdadero o falso.',
        ];
    }
}
