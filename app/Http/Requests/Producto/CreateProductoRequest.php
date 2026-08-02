<?php

namespace App\Http\Requests\Producto;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProductoRequest extends FormRequest
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

        if (! $this->exists('categoria_producto_id')) {
            $alias = $this->input('categoriaProductoId', $this->input('categoria_id', $this->input('categoriaId')));
            if ($alias !== null) {
                $merge['categoria_producto_id'] = $alias;
            }
        }

        if (! $this->exists('price')) {
            $alias = $this->input('precio');
            if ($alias !== null) {
                $merge['price'] = $alias;
            }
        }

        if (! $this->hasFile('image') && $this->hasFile('imagen')) {
            $this->files->set('image', $this->file('imagen'));
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
            'categoria_producto_id' => [
                'required',
                'integer',
                Rule::exists('categoria_productos', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('productos', 'name')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'price' => ['required', 'numeric', 'min:0'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'categoria_producto_id.required' => 'La categoría del producto es obligatoria.',
            'categoria_producto_id.exists' => 'La categoría seleccionada no existe en tu negocio.',
            'name.required' => 'El nombre del producto es obligatorio.',
            'name.max' => 'El nombre no puede superar :max caracteres.',
            'name.unique' => 'Ya existe un producto con ese nombre en tu negocio.',
            'price.required' => 'El precio es obligatorio.',
            'price.numeric' => 'El precio debe ser numérico.',
            'price.min' => 'El precio no puede ser negativo.',
            'image.image' => 'El archivo debe ser una imagen.',
            'image.mimes' => 'La imagen debe ser jpg, jpeg, png o webp.',
            'image.max' => 'La imagen no puede superar 2 MB.',
        ];
    }
}
