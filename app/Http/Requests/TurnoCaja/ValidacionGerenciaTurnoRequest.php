<?php

namespace App\Http\Requests\TurnoCaja;

use Illuminate\Foundation\Http\FormRequest;

class ValidacionGerenciaTurnoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('efectivo_gerencia')) {
            foreach (['efectivoGerencia', 'efectivo_gerencial', 'efectivo_contado', 'efectivoContado'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['efectivo_gerencia'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('terminal_gerencia')) {
            foreach (['terminalGerencia', 'terminal_gerencial', 'corte_tarjeta', 'corteTarjeta'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['terminal_gerencia'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('diferencia_gerencia')) {
            foreach ([
                'diferenciaGerencia',
                'direfencia_Gerencia',
                'direfenciaGerencia',
                'diferencia_Gerencia',
            ] as $alias) {
                if ($this->exists($alias)) {
                    $merge['diferencia_gerencia'] = $this->input($alias);
                    break;
                }
            }
        }

        $sobrante = $this->exists('sobrante') ? (float) $this->input('sobrante') : 0;
        $faltante = $this->exists('faltante') ? (float) $this->input('faltante') : 0;
        if ($sobrante > 0) {
            $merge['diferencia_gerencia'] = abs($sobrante);
        } elseif ($faltante > 0) {
            $merge['diferencia_gerencia'] = -abs($faltante);
        }

        if (! $this->exists('date_validation_gerencia')) {
            foreach (['dateValidationGerencia', 'fecha_validacion_gerencia'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['date_validation_gerencia'] = $this->input($alias);
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
            'efectivo_gerencia' => ['required', 'numeric', 'min:0'],
            'terminal_gerencia' => ['required', 'numeric', 'min:0'],
            'diferencia_gerencia' => ['required', 'numeric'],
            'date_validation_gerencia' => ['sometimes', 'nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'efectivo_gerencia.required' => 'El efectivo de gerencia es obligatorio.',
            'terminal_gerencia.required' => 'El total de terminal de gerencia es obligatorio.',
            'diferencia_gerencia.required' => 'La diferencia de gerencia es obligatoria.',
        ];
    }
}
