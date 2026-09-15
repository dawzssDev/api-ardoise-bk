<?php

namespace App\Http\Requests\Proveedor;

use App\Models\Proveedor;

trait PreparesProveedorComercialFields
{
    /**
     * @return array<string, mixed>
     */
    protected function mergeCamposComerciales(): array
    {
        $merge = [];

        if (! $this->exists('InsumosPrecios')) {
            foreach (['insumos_precios', 'insumosPrecios'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['InsumosPrecios'] = $this->input($alias);
                    break;
                }
            }
        }

        $rawInsumos = $merge['InsumosPrecios'] ?? $this->input('InsumosPrecios');
        if ($rawInsumos !== null) {
            $merge['InsumosPrecios'] = Proveedor::normalizeInsumosPrecios($rawInsumos);
        }

        if (! $this->exists('Incidencias')) {
            foreach (['incidencias'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['Incidencias'] = $this->input($alias);
                    break;
                }
            }
        }

        return $merge;
    }

    /**
     * @return array<string, mixed>
     */
    protected function camposComercialesRules(): array
    {
        $rules = [];

        foreach (Proveedor::CAMPOS_COMERCIALES_STRING as $field) {
            $max = $field === 'Incidencias' ? 500 : 255;
            $rules[$field] = ['sometimes', 'nullable', 'string', 'max:'.$max];
        }

        $rules['InsumosPrecios'] = [
            'sometimes',
            'nullable',
            'array',
            function (string $_attribute, mixed $value, \Closure $fail): void {
                if ($value === null) {
                    return;
                }

                if (! is_array($value)) {
                    $fail('InsumosPrecios debe enviarse como JSON (objeto o lista).');

                    return;
                }

                $encoded = json_encode($value);
                if (is_string($encoded) && strlen($encoded) > 8000) {
                    $fail('InsumosPrecios supera el tamaño máximo permitido.');

                    return;
                }

                $items = array_is_list($value) ? $value : [$value];
                foreach ($items as $index => $item) {
                    if (! is_array($item)) {
                        $fail("InsumosPrecios.{$index} debe ser un objeto con los datos del insumo.");
                    }
                }
            },
        ];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function camposComercialesMessages(): array
    {
        return [
            'InsumosPrecios.array' => 'InsumosPrecios debe enviarse como JSON (objeto o lista).',
        ];
    }
};
