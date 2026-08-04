<?php

namespace App\Http\Requests\Orden;

use App\Models\Orden;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrdenStatusRequest extends FormRequest
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
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'integer', Rule::in(Orden::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'El estatus es obligatorio.',
            'status.in' => 'Estatus de orden inválido.',
        ];
    }
}
