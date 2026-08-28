<?php

namespace App\Http\Requests\Orden;

use Illuminate\Foundation\Http\FormRequest;

class CancelarOrdenDetallesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('detalle_ids')) {
            foreach (['detalleIds', 'ids', 'detalles', 'detalle_id', 'detalleId'] as $alias) {
                if (! $this->exists($alias)) {
                    continue;
                }

                $value = $this->input($alias);
                $merge['detalle_ids'] = is_array($value) ? $value : [$value];
                break;
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'detalle_ids' => ['required', 'array', 'min:1'],
            'detalle_ids.*' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'detalle_ids.required' => 'Debes indicar al menos un producto de la orden para cancelar.',
            'detalle_ids.min' => 'Debes indicar al menos un producto de la orden para cancelar.',
            'detalle_ids.*.integer' => 'Cada detalle_id debe ser un número entero.',
        ];
    }
}
