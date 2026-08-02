<?php

namespace App\Http\Requests\Empleado;

use App\Models\Empleado;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ToggleEmpleadoStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('status') && $this->exists('estatus')) {
            $this->merge(['status' => $this->input('estatus')]);
        }

        if ($this->filled('status') && is_string($this->input('status'))) {
            $this->merge(['status' => strtolower(trim((string) $this->input('status')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(Empleado::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'Debes indicar el estatus del empleado.',
            'status.in' => 'El estatus debe ser activo, inactivo o baja.',
        ];
    }
}
