<?php

namespace App\Http\Requests\TurnoCaja;

use Illuminate\Foundation\Http\FormRequest;

class CreateDepositoEnTurnoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('descripcion')) {
            foreach (['description', 'concepto', 'detalle'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['descripcion'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('monto')) {
            foreach (['amount', 'importe', 'cantidad'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['monto'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('sucursal_id')) {
            foreach (['sucursalId', 'id_sucursal'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['sucursal_id'] = $this->input($alias);
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
            'descripcion' => ['required', 'string', 'max:500'],
            'monto' => ['required', 'numeric', 'gt:0'],
            'sucursal_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'descripcion.required' => 'La descripción es obligatoria.',
            'descripcion.max' => 'La descripción no puede superar :max caracteres.',
            'monto.required' => 'El monto es obligatorio.',
            'monto.gt' => 'El monto debe ser mayor a cero.',
        ];
    }
}
