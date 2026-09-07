<?php

namespace App\Http\Requests\MaeCuentaContaSuc;

use App\Models\MaeCuentaContaSucDetalle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateMaeCuentaContaSucDetalleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('mae_cuenta_conta_suc_id')) {
            foreach (['idMaeCuentaContaSuc', 'cuenta_id', 'cuentaId'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['mae_cuenta_conta_suc_id'] = $this->input($alias);
                    break;
                }
            }
        }

        foreach (['tipo_gasto', 'tipoGasto', 'tipo', 'tipo_movimiento', 'tipoMovimiento'] as $alias) {
            if (! $this->exists($alias)) {
                continue;
            }

            $normalizedGasto = MaeCuentaContaSucDetalle::normalizeTipoMovimiento($this->input($alias));
            if ($normalizedGasto !== null && MaeCuentaContaSucDetalle::isTipoGasto($normalizedGasto)) {
                $merge['tipo_movimiento'] = $normalizedGasto;
                break;
            }
        }

        if (! array_key_exists('tipo_movimiento', $merge) && ! $this->exists('tipo_movimiento')) {
            foreach (['tipoMovimiento', 'tipo'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['tipo_movimiento'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('descripcion_movimiento')) {
            foreach (['descripcionMovimiento', 'descripcion', 'concepto'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['descripcion_movimiento'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('monto_movimiento')) {
            foreach (['montoMovimiento', 'monto', 'importe'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['monto_movimiento'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('cuenta_origen_id')) {
            foreach (['cuentaOrigen', 'cuenta_origen', 'origen_id'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['cuenta_origen_id'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('cuenta_destino_id')) {
            foreach (['cuentaDestino', 'cuenta_destino', 'destino_id'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['cuenta_destino_id'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('status') && $this->exists('estatus')) {
            $merge['status'] = $this->input('estatus');
        }

        $tipoRaw = $merge['tipo_movimiento'] ?? $this->input('tipo_movimiento');
        $normalized = MaeCuentaContaSucDetalle::normalizeTipoMovimiento($tipoRaw);
        if ($normalized !== null) {
            $merge['tipo_movimiento'] = $normalized;
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
        $cuentaFromRoute = $this->route('id');

        $cuentaRules = $cuentaFromRoute
            ? ['sometimes', 'integer']
            : [
                'required',
                'integer',
                Rule::exists('mae_cuenta_conta_suc', 'id')->where(
                    fn ($q) => $q
                        ->where('negocio_id', $negocioId)
                        ->where('deleted', 0)
                ),
            ];

        $esGasto = MaeCuentaContaSucDetalle::isTipoGasto($this->input('tipo_movimiento'));
        $origenId = (int) $this->input('cuenta_origen_id');
        $destinoId = (int) $this->input('cuenta_destino_id');
        $cuentaRuta = (int) $this->route('id');
        $gastoSobreMismaCuenta = $origenId > 0 && (
            $origenId === $destinoId
            || ($destinoId < 1 && ($cuentaRuta < 1 || $origenId === $cuentaRuta))
        );
        $esConCuentas = ! $esGasto && ! $gastoSobreMismaCuenta && in_array($this->input('tipo_movimiento'), [
            MaeCuentaContaSucDetalle::TIPO_TRANSFERENCIA,
            MaeCuentaContaSucDetalle::TIPO_RETIRO,
            MaeCuentaContaSucDetalle::TIPO_VENTA_EFECTIVO,
            MaeCuentaContaSucDetalle::TIPO_VENTA_TARJETA,
        ], true);

        return [
            'mae_cuenta_conta_suc_id' => $cuentaRules,
            'tipo_movimiento' => ['required', 'string', Rule::in(MaeCuentaContaSucDetalle::TIPOS_MOVIMIENTO)],
            'descripcion_movimiento' => ['required', 'string', 'max:500'],
            'monto_movimiento' => ['required', 'numeric', 'gt:0'],
            'cuenta_origen_id' => [
                Rule::requiredIf($esConCuentas),
                'nullable',
                'integer',
                Rule::exists('mae_cuenta_conta_suc', 'id')->where(
                    fn ($q) => $q
                        ->where('negocio_id', $negocioId)
                        ->where('deleted', 0)
                ),
            ],
            'cuenta_destino_id' => [
                Rule::requiredIf($esConCuentas),
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('mae_cuenta_conta_suc', 'id')->where(
                    fn ($q) => $q
                        ->where('negocio_id', $negocioId)
                        ->where('deleted', 0)
                ),
                Rule::notIn($esConCuentas ? [(int) $this->input('cuenta_origen_id')] : []),
            ],
            'status' => ['sometimes', 'integer', Rule::in([MaeCuentaContaSucDetalle::STATUS_INACTIVO, MaeCuentaContaSucDetalle::STATUS_ACTIVO])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mae_cuenta_conta_suc_id.required' => 'La cuenta contable es obligatoria.',
            'mae_cuenta_conta_suc_id.exists' => 'La cuenta contable no existe, no pertenece a tu negocio o está eliminada.',
            'tipo_movimiento.required' => 'El tipo de movimiento es obligatorio.',
            'tipo_movimiento.in' => 'El tipo de movimiento debe ser deposito, transferencia, retiro, gasto, gasto_operativo, pago_proveedor, retiro_efectivo, venta_efectivo, venta_tarjeta o comision_terminal.',
            'descripcion_movimiento.required' => 'La descripción del movimiento es obligatoria.',
            'descripcion_movimiento.max' => 'La descripción no puede superar :max caracteres.',
            'monto_movimiento.required' => 'El monto del movimiento es obligatorio.',
            'monto_movimiento.gt' => 'El monto del movimiento debe ser mayor a cero.',
            'cuenta_origen_id.required' => 'La cuenta origen es obligatoria para transferencia, retiro o venta de corte.',
            'cuenta_origen_id.exists' => 'La cuenta origen no existe, no pertenece a tu negocio o está eliminada.',
            'cuenta_destino_id.exists' => 'La cuenta destino no existe, no pertenece a tu negocio o está eliminada.',
            'cuenta_destino_id.required' => 'La cuenta destino es obligatoria para transferencia, retiro o venta de corte.',
            'cuenta_destino_id.not_in' => 'La cuenta origen y la cuenta destino deben ser distintas.',
            'status.in' => 'El status debe ser 1 (activo) o 0 (inactivo).',
        ];
    }
}
