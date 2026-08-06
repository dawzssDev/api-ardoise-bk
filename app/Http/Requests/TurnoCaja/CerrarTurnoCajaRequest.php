<?php

namespace App\Http\Requests\TurnoCaja;

use Illuminate\Foundation\Http\FormRequest;

class CerrarTurnoCajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('efectivo_real')) {
            foreach (['efectivoReal', 'efectivo_contado', 'efectivoContado', 'monto_real'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['efectivo_real'] = $this->input($alias);
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
            'efectivo_real' => ['required', 'numeric', 'min:0'],
            'observaciones_cierre' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'efectivo_real.required' => 'Debes indicar el efectivo real contado en caja.',
            'efectivo_real.min' => 'El efectivo real no puede ser negativo.',
        ];
    }
}
