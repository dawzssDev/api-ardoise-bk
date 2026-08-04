<?php

namespace App\Http\Requests\Orden;

use App\Models\OrdenDetalle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrdenDetalleStatusRequest extends FormRequest
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
            'status' => ['required', 'integer', Rule::in(OrdenDetalle::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => 'El estatus es obligatorio.',
            'status.in' => 'Estatus de detalle inválido.',
        ];
    }
}
