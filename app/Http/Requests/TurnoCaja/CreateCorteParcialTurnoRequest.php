<?php

namespace App\Http\Requests\TurnoCaja;

use Illuminate\Foundation\Http\FormRequest;

class CreateCorteParcialTurnoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('efectivo_real_cajera')) {
            foreach ([
                'efectivoRealCajera',
                'efectivo_real',
                'efectivoReal',
                'efectivo_contado_cajera',
                'efectivoContadoCajera',
                'efectivo_contado',
            ] as $alias) {
                if ($this->exists($alias)) {
                    $merge['efectivo_real_cajera'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('observaciones_cierre')) {
            foreach (['observaciones', 'observacionesCierre', 'notas_cierre', 'notas'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['observaciones_cierre'] = $this->input($alias);
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
            'efectivo_real_cajera' => ['required', 'numeric', 'min:0'],
            'observaciones_cierre' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'sucursal_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'efectivo_real_cajera.required' => 'Debes indicar el efectivo real contado en caja.',
            'efectivo_real_cajera.min' => 'El efectivo real de la cajera no puede ser negativo.',
        ];
    }
}
