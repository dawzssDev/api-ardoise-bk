<?php

namespace App\Http\Requests\Proveedor;

use App\Models\Proveedor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('name')) {
            foreach (['nombre', 'nombre_comercial', 'nombreComercial'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['name'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('legal_name')) {
            foreach (['razon_social', 'razonSocial', 'legalName'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['legal_name'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('phone')) {
            foreach (['telefono', 'tel'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['phone'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('email')) {
            foreach (['correo', 'correo_electronico'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['email'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('address')) {
            foreach (['direccion', 'domicilio'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['address'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('contact_name')) {
            foreach (['contacto', 'nombre_contacto', 'nombreContacto'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['contact_name'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('notes')) {
            foreach (['notas', 'observaciones'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['notes'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('status') && $this->exists('estatus')) {
            $merge['status'] = $this->input('estatus');
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
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('proveedores', 'name')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'rfc' => ['sometimes', 'nullable', 'string', 'max:13'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'integer', Rule::in([Proveedor::STATUS_BAJA, Proveedor::STATUS_ACTIVO])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del proveedor es obligatorio.',
            'name.max' => 'El nombre no puede superar :max caracteres.',
            'name.unique' => 'Ya existe un proveedor con ese nombre en tu negocio.',
            'email.email' => 'El correo del proveedor no es válido.',
            'status.in' => 'El status del proveedor debe ser 1 (activo) o 0 (baja).',
        ];
    }
}
