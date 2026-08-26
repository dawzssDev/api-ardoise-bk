<?php

namespace App\Http\Requests\MaeCuentaContaSuc;

use App\Models\MaeCuentaContaSucDetalle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaeCuentaContaSucDetalleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('tipo_movimiento')) {
            foreach (['tipoMovimiento', 'tipo'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['tipo_movimiento'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('descripcion_movimiento')) {
            foreach (['descripcionMovimiento', 'descripcion'] as $alias) {
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
        $esTransferencia = $this->input('tipo_movimiento') === MaeCuentaContaSucDetalle::TIPO_TRANSFERENCIA;

        return [
            'tipo_movimiento' => ['sometimes', 'required', 'string', Rule::in(MaeCuentaContaSucDetalle::TIPOS_MOVIMIENTO)],
            'descripcion_movimiento' => ['sometimes', 'required', 'string', 'max:500'],
            'monto_movimiento' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'cuenta_origen_id' => [
                Rule::requiredIf($esTransferencia),
                'nullable',
                'integer',
                Rule::exists('mae_cuenta_conta_suc', 'id')->where(
                    fn ($q) => $q
                        ->where('negocio_id', $negocioId)
                        ->where('deleted', 0)
                ),
            ],
            'cuenta_destino_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('mae_cuenta_conta_suc', 'id')->where(
                    fn ($q) => $q
                        ->where('negocio_id', $negocioId)
                        ->where('deleted', 0)
                ),
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
            'tipo_movimiento.in' => 'El tipo de movimiento debe ser deposito o transferencia.',
            'descripcion_movimiento.required' => 'La descripción del movimiento es obligatoria.',
            'descripcion_movimiento.max' => 'La descripción no puede superar :max caracteres.',
            'monto_movimiento.gt' => 'El monto del movimiento debe ser mayor a cero.',
            'cuenta_origen_id.required' => 'La cuenta origen es obligatoria cuando el movimiento es transferencia.',
            'cuenta_origen_id.exists' => 'La cuenta origen no existe, no pertenece a tu negocio o está eliminada.',
            'cuenta_destino_id.exists' => 'La cuenta destino no existe, no pertenece a tu negocio o está eliminada.',
            'status.in' => 'El status debe ser 1 (activo) o 0 (inactivo).',
        ];
    }
}
