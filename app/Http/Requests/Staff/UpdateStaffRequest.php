<?php

namespace App\Http\Requests\Staff;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('username')) {
            foreach (['usuario', 'user', 'login'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['username'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('password')) {
            foreach (['contraseña', 'contrasena'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['password'] = $this->input($alias);
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

        if (! $this->exists('role_id')) {
            foreach (['rol_id', 'rolId', 'roleId'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['role_id'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('empleado_id')) {
            foreach (['empleadoResponsable', 'empleado_responsable', 'empleadoId', 'id_empleado'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['empleado_id'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('status') && $this->exists('activo')) {
            $merge['status'] = $this->input('activo');
        } elseif (! $this->exists('status') && $this->exists('estatus')) {
            $merge['status'] = $this->input('estatus');
        }

        // Contraseña vacía = no actualizar
        $password = $merge['password'] ?? $this->input('password');
        if ($password === '' || $password === null) {
            $merge['password'] = null;
            $this->request->remove('password');
        }

        if ($merge !== []) {
            $this->merge(array_filter(
                $merge,
                static fn ($value, $key) => ! ($key === 'password' && ($value === null || $value === '')),
                ARRAY_FILTER_USE_BOTH
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $negocioId = $this->user()?->negocio?->id;
        $staffId = (int) $this->route('id');

        return [
            'username' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('staff', 'username')
                    ->where(fn ($q) => $q->where('negocio_id', $negocioId))
                    ->ignore($staffId),
            ],
            'password' => ['sometimes', 'nullable', 'string', 'min:6', 'max:100'],
            'sucursal_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('sucursales', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'role_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('roles', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'empleado_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('empleados', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
                Rule::unique('staff', 'empleado_id')->ignore($staffId),
            ],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'username.required' => 'El usuario es obligatorio.',
            'username.unique' => 'Ya existe un staff con ese usuario en tu negocio.',
            'password.min' => 'La contraseña debe tener al menos :min caracteres.',
            'sucursal_id.exists' => 'La sucursal no existe en tu negocio.',
            'role_id.exists' => 'El rol no existe en tu negocio.',
            'empleado_id.exists' => 'El empleado no existe en tu negocio.',
            'empleado_id.unique' => 'Ese empleado ya tiene un usuario staff asignado.',
            'status.boolean' => 'El status debe ser verdadero o falso.',
        ];
    }
}
