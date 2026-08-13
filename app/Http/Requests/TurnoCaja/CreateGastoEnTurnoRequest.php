<?php

namespace App\Http\Requests\TurnoCaja;

use App\Models\GastoEnTurno;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateGastoEnTurnoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('tipo_gasto')) {
            foreach (['tipo', 'tipoGasto', 'type'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['tipo_gasto'] = $this->input($alias);
                    break;
                }
            }
        }

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

        $tipoRaw = $merge['tipo_gasto'] ?? $this->input('tipo_gasto');
        $normalized = GastoEnTurno::normalizeTipo($tipoRaw);
        if ($normalized !== null) {
            $merge['tipo_gasto'] = $normalized;
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
            'tipo_gasto' => ['required', 'string', Rule::in(GastoEnTurno::TIPOS)],
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
            'tipo_gasto.required' => 'El tipo de gasto es obligatorio.',
            'tipo_gasto.in' => 'El tipo de gasto debe ser Pago proveedor, Gasto operativo o Retiro de efectivo.',
            'descripcion.required' => 'La descripción es obligatoria.',
            'descripcion.max' => 'La descripción no puede superar :max caracteres.',
            'monto.required' => 'El monto es obligatorio.',
            'monto.gt' => 'El monto debe ser mayor a cero.',
        ];
    }
}
