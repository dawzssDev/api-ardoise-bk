<?php

namespace App\Http\Requests\Producto;

use App\Models\Producto;
use Illuminate\Validation\Validator;

trait PreparesDescuentoStockFields
{
    /**
     * @return array<string, mixed>
     */
    protected function mergeDescuentoStockAliases(): array
    {
        $merge = [];

        if (! $this->exists('descuento_stock_prod')) {
            $alias = $this->input('descuentoStockProd', $this->input('descuento_stock_producto'));
            if ($alias !== null) {
                $merge['descuento_stock_prod'] = $alias;
            }
        }

        if (! $this->exists('descuento_stock_insum')) {
            $alias = $this->input('descuentoStockInsum', $this->input('descuento_stock_insumo'));
            if ($alias !== null) {
                $merge['descuento_stock_insum'] = $alias;
            }
        }

        $rawProd = $merge['descuento_stock_prod'] ?? $this->input('descuento_stock_prod');
        if ($rawProd !== null) {
            $merge['descuento_stock_prod'] = Producto::normalizeDescuentoStockProd($rawProd);
        }

        $rawInsum = $merge['descuento_stock_insum'] ?? $this->input('descuento_stock_insum');
        if ($rawInsum !== null) {
            $merge['descuento_stock_insum'] = Producto::normalizeDescuentoStockInsum($rawInsum);
        }

        return $merge;
    }

    /**
     * @return array<string, mixed>
     */
    protected function descuentoStockRules(): array
    {
        return [
            'descuento_stock_prod' => ['sometimes', 'nullable', 'array'],
            'descuento_stock_prod.*' => ['required', 'array', function (string $_attribute, mixed $value, \Closure $fail): void {
                if (! is_array($value)) {
                    $fail('Cada descuento de producto debe ser un objeto.');

                    return;
                }

                $hasId = ! empty($value['id']);
                $hasName = isset($value['Producto']) && trim((string) $value['Producto']) !== '';
                if (! $hasId && ! $hasName) {
                    $fail('Cada descuento de producto debe indicar el producto (id o nombre).');
                }
            }],
            'descuento_stock_prod.*.id' => ['nullable', 'integer'],
            'descuento_stock_prod.*.Producto' => ['nullable', 'string', 'max:150'],
            'descuento_stock_prod.*.cantidad' => ['required', 'numeric', 'min:0.001'],
            'descuento_stock_insum' => ['sometimes', 'nullable', 'array'],
            'descuento_stock_insum.*' => ['required', 'array', function (string $_attribute, mixed $value, \Closure $fail): void {
                if (! is_array($value)) {
                    $fail('Cada descuento de insumo debe ser un objeto.');

                    return;
                }

                $hasId = ! empty($value['id']);
                $hasName = isset($value['Insumo']) && trim((string) $value['Insumo']) !== '';
                if (! $hasId && ! $hasName) {
                    $fail('Cada descuento de insumo debe indicar el insumo (id o nombre).');
                }
            }],
            'descuento_stock_insum.*.id' => ['nullable', 'integer'],
            'descuento_stock_insum.*.Insumo' => ['nullable', 'string', 'max:150'],
            'descuento_stock_insum.*.cantidad' => ['required', 'numeric', 'min:0.001'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function descuentoStockMessages(): array
    {
        return [
            'descuento_stock_prod.array' => 'El descuento de stock de productos debe enviarse como lista.',
            'descuento_stock_prod.*.array' => 'Cada descuento de producto debe ser un objeto.',
            'descuento_stock_prod.*.cantidad.required' => 'La cantidad a descontar del producto es obligatoria.',
            'descuento_stock_prod.*.cantidad.numeric' => 'La cantidad a descontar del producto debe ser numérica.',
            'descuento_stock_prod.*.cantidad.min' => 'La cantidad a descontar del producto debe ser mayor a 0.',
            'descuento_stock_insum.array' => 'El descuento de stock de insumos debe enviarse como lista.',
            'descuento_stock_insum.*.array' => 'Cada descuento de insumo debe ser un objeto.',
            'descuento_stock_insum.*.cantidad.required' => 'La cantidad a descontar del insumo es obligatoria.',
            'descuento_stock_insum.*.cantidad.numeric' => 'La cantidad a descontar del insumo debe ser numérica.',
            'descuento_stock_insum.*.cantidad.min' => 'La cantidad a descontar del insumo debe ser mayor a 0.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $negocioId = $this->user()?->negocio?->id;
            if (! $negocioId) {
                return;
            }

            if ($this->exists('descuento_stock_prod') && is_array($this->input('descuento_stock_prod'))) {
                [$resolved, $errors] = Producto::resolveDescuentoStockProd(
                    (int) $negocioId,
                    $this->input('descuento_stock_prod'),
                );

                foreach ($errors as $key => $message) {
                    $validator->errors()->add($key, $message);
                }

                if ($errors === []) {
                    $this->replaceResolvedDescuento($validator, 'descuento_stock_prod', $resolved);
                }
            }

            if ($this->exists('descuento_stock_insum') && is_array($this->input('descuento_stock_insum'))) {
                [$resolved, $errors] = Producto::resolveDescuentoStockInsum(
                    (int) $negocioId,
                    $this->input('descuento_stock_insum'),
                );

                foreach ($errors as $key => $message) {
                    $validator->errors()->add($key, $message);
                }

                if ($errors === []) {
                    $this->replaceResolvedDescuento($validator, 'descuento_stock_insum', $resolved);
                }
            }
        });
    }

    /**
     * @param  list<array<string, mixed>>  $resolved
     */
    private function replaceResolvedDescuento(Validator $validator, string $field, array $resolved): void
    {
        $this->merge([$field => $resolved]);
        $validator->setData(array_merge($validator->getData(), [$field => $resolved]));
    }
}
