<?php

namespace App\Http\Requests\CategoriaInsumo;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCategoriaInsumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->exists('name') && $this->exists('nombre')) {
            $this->merge(['name' => $this->input('nombre')]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $negocioId = $this->user()?->negocio?->id;

        return [
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('categoria_insumos', 'name')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la categoría es obligatorio.',
            'name.max' => 'El nombre no puede superar :max caracteres.',
            'name.unique' => 'Ya existe una categoría con ese nombre en tu negocio.',
        ];
    }
}
