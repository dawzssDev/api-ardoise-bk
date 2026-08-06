<?php

namespace App\Http\Requests\TurnoCaja;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AbrirTurnoCajaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('sucursal_id')) {
            foreach (['sucursalId', 'id_sucursal'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['sucursal_id'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('fondo_inicial')) {
            foreach (['fondoInicial', 'fondo', 'monto_inicial'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['fondo_inicial'] = $this->input($alias);
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
        $negocioId = $this->user()?->negocio?->id;

        return [
            'sucursal_id' => [
                'nullable',
                'integer',
                Rule::exists('sucursales', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'fondo_inicial' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sucursal_id.exists' => 'La sucursal no existe en tu negocio.',
            'fondo_inicial.min' => 'El fondo inicial no puede ser negativo.',
        ];
    }
}
