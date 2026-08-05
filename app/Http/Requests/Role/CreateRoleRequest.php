<?php

namespace App\Http\Requests\Role;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('name')) {
            $alias = $this->input('nameRole', $this->input('nombre', $this->input('name_role')));
            if ($alias !== null) {
                $merge['name'] = $alias;
            }
        }

        if (! $this->exists('permissions')) {
            $alias = $this->input('permisosAccess', $this->input('permisos_access', $this->input('permisos')));
            if ($alias !== null) {
                $merge['permissions'] = $alias;
            }
        }

        if (! $this->exists('status') && $this->exists('activo')) {
            $merge['status'] = $this->input('activo');
        }

        $rawPermissions = $merge['permissions'] ?? $this->input('permissions');

        if (is_string($rawPermissions)) {
            $decoded = json_decode($rawPermissions, true);
            $rawPermissions = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        if ($rawPermissions === null) {
            $merge['permissions'] = Role::defaultPermissions();
        } elseif (is_array($rawPermissions)) {
            // Lista antigua ["pos","kitchen"] → mapa booleano
            if (array_is_list($rawPermissions)) {
                $map = Role::defaultPermissions();
                foreach ($rawPermissions as $key) {
                    if (! is_string($key)) {
                        continue;
                    }
                    $canonical = Role::PERMISSION_ALIASES[$key] ?? $key;
                    if (array_key_exists($canonical, $map)) {
                        $map[$canonical] = true;
                    }
                }
                $merge['permissions'] = $map;
            } else {
                $merge['permissions'] = Role::normalizePermissions($rawPermissions);
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * Asegura el mapa completo de permisos (validated() a veces omite claves nuevas).
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function validated($key = null, $default = null): mixed
    {
        $validated = parent::validated();

        if (array_key_exists('permissions', $validated) || $this->exists('permissions')) {
            $validated['permissions'] = Role::normalizePermissions(
                is_array($this->input('permissions')) ? $this->input('permissions') : null
            );
        }

        if ($key !== null) {
            return data_get($validated, $key, $default);
        }

        return $validated;
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
                'max:100',
                Rule::unique('roles', 'name')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'permissions' => ['required', 'array'],
            'permissions.pos' => ['required', 'boolean'],
            'permissions.kitchen' => ['required', 'boolean'],
            'permissions.branch_inventory' => ['required', 'boolean'],
            'permissions.central_warehouse' => ['required', 'boolean'],
            'permissions.branches' => ['required', 'boolean'],
            'permissions.insumos' => ['required', 'boolean'],
            'permissions.stock_insumos' => ['required', 'boolean'],
            'permissions.products' => ['required', 'boolean'],
            'permissions.finance' => ['required', 'boolean'],
            'permissions.staff' => ['required', 'boolean'],
            'permissions.supply_requests' => ['required', 'boolean'],
            'permissions.business' => ['required', 'boolean'],
            'permissions.users' => ['required', 'boolean'],
            'permissions.roles_permissions' => ['required', 'boolean'],
            'permissions.nuevoPedido' => ['required', 'boolean'],
            'permissions.enPreparacionPedido' => ['required', 'boolean'],
            'permissions.pedidosListos' => ['required', 'boolean'],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del rol es obligatorio.',
            'name.max' => 'El nombre no puede superar :max caracteres.',
            'name.unique' => 'Ya existe un rol con ese nombre en tu negocio.',
            'permissions.required' => 'Los permisos son obligatorios.',
            'permissions.array' => 'Los permisos deben enviarse como objeto.',
            'permissions.*.boolean' => 'Cada permiso debe ser verdadero o falso.',
            'status.boolean' => 'El status debe ser verdadero o falso.',
        ];
    }
}
