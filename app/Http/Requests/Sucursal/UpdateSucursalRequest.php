<?php

namespace App\Http\Requests\Sucursal;

use App\Models\Sucursal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSucursalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $aliases = [
            'type' => ['tipo'],
            'name' => ['nombre'],
            'is_active' => ['activa', 'active'],
            'street' => ['calle'],
            'neighborhood' => ['colonia', 'colonia_barrio'],
            'city' => ['ciudad'],
            'state' => ['estado'],
            'postal_code' => ['codigo_postal', 'cp', 'zip'],
            'opened_year' => ['anio_apertura', 'año_apertura', 'openedYear'],
        ];

        $merge = [];

        foreach ($aliases as $field => $keys) {
            if ($this->exists($field)) {
                continue;
            }

            foreach ($keys as $alias) {
                if ($this->exists($alias)) {
                    $merge[$field] = $this->input($alias);
                    break;
                }
            }
        }

        if (array_key_exists('is_active', $merge) || $this->exists('is_active')) {
            $raw = $merge['is_active'] ?? $this->input('is_active');
            $merge['is_active'] = $this->toBoolean($raw);
        }

        if (array_key_exists('type', $merge) || $this->exists('type')) {
            $type = $merge['type'] ?? $this->input('type');
            if (is_string($type)) {
                $merge['type'] = strtolower(trim($type));
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
            'type' => ['sometimes', 'required', 'string', Rule::in(Sucursal::TYPES)],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            'street' => ['sometimes', 'nullable', 'string', 'max:255'],
            'neighborhood' => ['sometimes', 'nullable', 'string', 'max:150'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'opened_year' => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:'.((int) date('Y') + 1)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'El tipo es obligatorio.',
            'type.in' => 'El tipo debe ser sucursal o bodega.',
            'name.required' => 'El nombre es obligatorio.',
            'name.max' => 'El nombre no puede superar :max caracteres.',
            'is_active.boolean' => 'El campo activa debe ser verdadero o falso.',
            'opened_year.integer' => 'El año de apertura debe ser un número.',
            'opened_year.min' => 'El año de apertura no es válido.',
            'opened_year.max' => 'El año de apertura no es válido.',
        ];
    }

    private function toBoolean(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'on', 'yes', 'si', 'sí' => true,
            '0', 'false', 'off', 'no' => false,
            default => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        };
    }
}
