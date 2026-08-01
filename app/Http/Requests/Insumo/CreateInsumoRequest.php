<?php

namespace App\Http\Requests\Insumo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInsumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('name') && $this->exists('nombre')) {
            $merge['name'] = $this->input('nombre');
        }

        if (! $this->exists('categoria_insumo_id')) {
            $alias = $this->input('categoria_id', $this->input('categoriaId'));
            if ($alias !== null) {
                $merge['categoria_insumo_id'] = $alias;
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
            'categoria_insumo_id' => [
                'required',
                'integer',
                Rule::exists('categoria_insumos', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('insumos', 'name')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'status_insumo' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'categoria_insumo_id.required' => 'La categoría del insumo es obligatoria.',
            'categoria_insumo_id.exists' => 'La categoría seleccionada no existe en tu negocio.',
            'name.required' => 'El nombre del insumo es obligatorio.',
            'name.max' => 'El nombre no puede superar :max caracteres.',
            'name.unique' => 'Ya existe un insumo con ese nombre en tu negocio.',
            'status_insumo.boolean' => 'El status del insumo debe ser verdadero o falso.',
        ];
    }
}
