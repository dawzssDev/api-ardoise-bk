<?php

namespace App\Http\Requests\Orden;

use App\Models\Orden;
use App\Models\OrdenDetalle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOrdenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('customer_name')) {
            foreach (['nombre_cliente', 'nombreCliente', 'nombre_pedido', 'nombrePedido'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['customer_name'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('sucursal_id')) {
            foreach (['sucursalId', 'SucursalID', 'id_sucursal'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['sucursal_id'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('payment_type')) {
            foreach (['tipo_pago', 'tipoPago', 'TipoPAGO', 'tipo_pago'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['payment_type'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('status') && $this->exists('estatus')) {
            $merge['status'] = $this->input('estatus');
        }

        if (! $this->exists('detalles')) {
            foreach (['items', 'productos', 'orden_detalle', 'detalle'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['detalles'] = $this->input($alias);
                    break;
                }
            }
        }

        $detalles = $merge['detalles'] ?? $this->input('detalles');
        if (is_array($detalles)) {
            $normalized = [];
            foreach ($detalles as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $row = $item;
                if (! array_key_exists('producto_id', $row)) {
                    $row['producto_id'] = $row['productoId'] ?? $row['ProductoID'] ?? $row['id_producto'] ?? null;
                }
                if (! array_key_exists('product_name', $row)) {
                    $row['product_name'] = $row['nombre_pedido'] ?? $row['nombrePedido'] ?? $row['NombrePedido'] ?? null;
                }
                if (! array_key_exists('quantity', $row)) {
                    $row['quantity'] = $row['cantidad'] ?? $row['Cantidad'] ?? null;
                }
                if (! array_key_exists('price', $row)) {
                    $row['price'] = $row['precio'] ?? $row['Precio'] ?? null;
                }
                if (! array_key_exists('notes', $row)) {
                    $row['notes'] = $row['observaciones'] ?? $row['Observaciones'] ?? null;
                }
                if (! array_key_exists('status', $row) && array_key_exists('estatus', $row)) {
                    $row['status'] = $row['estatus'];
                }
                if (! array_key_exists('extras', $row) && array_key_exists('Extras', $row)) {
                    $row['extras'] = $row['Extras'];
                }
                $normalized[] = $row;
            }
            $merge['detalles'] = $normalized;
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
            'customer_name' => ['required', 'string', 'max:255'],
            'sucursal_id' => [
                'nullable',
                'integer',
                Rule::exists('sucursales', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'payment_type' => ['required', 'string', 'max:30'],
            'status' => ['sometimes', 'integer', Rule::in(Orden::STATUSES)],
            'detalles' => ['required', 'array', 'min:1'],
            'detalles.*.producto_id' => [
                'required',
                'integer',
                Rule::exists('productos', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'detalles.*.product_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'detalles.*.quantity' => ['required', 'numeric', 'gt:0'],
            'detalles.*.price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'detalles.*.extras' => ['sometimes', 'nullable', 'array'],
            'detalles.*.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'detalles.*.status' => ['sometimes', 'integer', Rule::in(OrdenDetalle::STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_name.required' => 'El nombre del cliente/pedido es obligatorio.',
            'payment_type.required' => 'El tipo de pago es obligatorio.',
            'detalles.required' => 'Debes enviar al menos un producto en la orden.',
            'detalles.min' => 'Debes enviar al menos un producto en la orden.',
            'detalles.*.producto_id.required' => 'El producto es obligatorio.',
            'detalles.*.producto_id.exists' => 'El producto no existe en tu negocio.',
            'detalles.*.quantity.required' => 'La cantidad es obligatoria.',
            'detalles.*.quantity.gt' => 'La cantidad debe ser mayor a cero.',
        ];
    }
}
