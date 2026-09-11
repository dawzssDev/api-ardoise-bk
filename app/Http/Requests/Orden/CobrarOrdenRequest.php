<?php

namespace App\Http\Requests\Orden;

use Illuminate\Foundation\Http\FormRequest;

class CobrarOrdenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('payment_type')) {
            foreach (['tipo_pago', 'tipoPago', 'TipoPAGO'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['payment_type'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('pagos')) {
            foreach (['payments', 'formas_pago', 'formasPago'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['pagos'] = $this->input($alias);
                    break;
                }
            }
        }

        $pagos = $merge['pagos'] ?? $this->input('pagos');
        if (is_array($pagos)) {
            $normalizedPagos = [];
            foreach ($pagos as $pago) {
                if (! is_array($pago)) {
                    continue;
                }
                $row = $pago;
                if (! array_key_exists('payment_type', $row)) {
                    $row['payment_type'] = $row['tipo_pago'] ?? $row['tipoPago'] ?? $row['TipoPAGO'] ?? null;
                }
                if (! array_key_exists('amount', $row)) {
                    $row['amount'] = $row['monto'] ?? $row['Monto'] ?? $row['importe'] ?? null;
                }
                $normalizedPagos[] = $row;
            }
            $merge['pagos'] = $normalizedPagos;
        }

        if (! $this->exists('seconds_in_caja')) {
            foreach (['tiempo_en_caja', 'tiempoEnCaja', 'secondsInCaja'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['seconds_in_caja'] = $this->input($alias);
                    break;
                }
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
            'payment_type' => ['required_without:pagos', 'nullable', 'string', 'max:30'],
            'pagos' => ['required_without:payment_type', 'nullable', 'array', 'min:1'],
            'pagos.*.payment_type' => ['required_with:pagos', 'string', 'max:30'],
            'pagos.*.amount' => ['required_with:pagos', 'numeric', 'gt:0'],
            'seconds_in_caja' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payment_type.required_without' => 'Envía tipo_pago o el arreglo pagos.',
            'pagos.required_without' => 'Envía tipo_pago o el arreglo pagos.',
            'pagos.min' => 'Debes enviar al menos una forma de pago.',
            'pagos.*.payment_type.required_with' => 'Cada pago debe incluir tipo_pago.',
            'pagos.*.amount.required_with' => 'Cada pago debe incluir monto.',
            'pagos.*.amount.gt' => 'Cada monto de pago debe ser mayor a cero.',
        ];
    }
}
