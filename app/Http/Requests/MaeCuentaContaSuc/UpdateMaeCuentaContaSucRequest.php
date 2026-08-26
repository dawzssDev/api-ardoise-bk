<?php

namespace App\Http\Requests\MaeCuentaContaSuc;

use App\Models\MaeCuentaContaSuc;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMaeCuentaContaSucRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('tipo_cuenta')) {
            foreach (['tipoCuenta', 'tipo'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['tipo_cuenta'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('sucursal_id')) {
            foreach (['SucursaliD', 'sucursalID', 'sucursalId', 'id_sucursal'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['sucursal_id'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('titulo_cuenta')) {
            foreach (['tituloCuenta', 'titulo'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['titulo_cuenta'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('descripcion_cuenta')) {
            foreach (['descripcionCuenta', 'descripcion'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['descripcion_cuenta'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('status') && $this->exists('estatus')) {
            $merge['status'] = $this->input('estatus');
        }

        $tipoRaw = $merge['tipo_cuenta'] ?? $this->input('tipo_cuenta');
        $normalized = MaeCuentaContaSuc::normalizeTipoCuenta($tipoRaw);
        if ($normalized !== null) {
            $merge['tipo_cuenta'] = $normalized;
        }

        $tipoFinal = $merge['tipo_cuenta'] ?? $this->input('tipo_cuenta');
        if ($tipoFinal === MaeCuentaContaSuc::TIPO_MAESTRA) {
            $merge['sucursal_id'] = null;
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
        $esSubcuenta = $this->input('tipo_cuenta') === MaeCuentaContaSuc::TIPO_SUBCUENTA;

        return [
            'tipo_cuenta' => ['sometimes', 'required', 'string', Rule::in(MaeCuentaContaSuc::TIPOS)],
            'sucursal_id' => [
                Rule::requiredIf($esSubcuenta),
                'nullable',
                'integer',
                Rule::exists('sucursales', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'titulo_cuenta' => ['sometimes', 'required', 'string', 'max:150'],
            'descripcion_cuenta' => ['sometimes', 'nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'integer', Rule::in([MaeCuentaContaSuc::STATUS_INACTIVO, MaeCuentaContaSuc::STATUS_ACTIVO])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo_cuenta.in' => 'El tipo de cuenta debe ser maestra o subcuenta.',
            'sucursal_id.required' => 'La sucursal es obligatoria cuando la cuenta es subcuenta.',
            'sucursal_id.exists' => 'La sucursal no existe o no pertenece a tu negocio.',
            'titulo_cuenta.required' => 'El título de la cuenta es obligatorio.',
            'titulo_cuenta.max' => 'El título no puede superar :max caracteres.',
            'status.in' => 'El status debe ser 1 (activo) o 0 (inactivo).',
        ];
    }
}
