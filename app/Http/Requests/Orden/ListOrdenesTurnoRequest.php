<?php

namespace App\Http\Requests\Orden;

use Illuminate\Foundation\Http\FormRequest;

class ListOrdenesTurnoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->filled('sucursal_id')) {
            foreach (['sucursalId', 'SucursalID', 'id_sucursal'] as $alias) {
                if ($this->filled($alias)) {
                    $merge['sucursal_id'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->filled('turno_id')) {
            foreach (['turnoId', 'TurnoID', 'id_turno', 'turno_caja_id'] as $alias) {
                if ($this->filled($alias)) {
                    $merge['turno_id'] = $this->input($alias);
                    break;
                }
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
            'sucursal_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'turno_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
