<?php

namespace App\Http\Requests\TipoVenta;

use App\Models\TipoVenta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTipoVentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->exists('name') && $this->exists('nombre')) {
            $merge['name'] = $this->input('nombre');
        }

        if (! $this->exists('tipo_descuento')) {
            foreach (['tipoDescuento', 'descuento_tipo'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['tipo_descuento'] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->exists('valor_descuento')) {
            foreach (['valorDescuento', 'descuento_valor'] as $alias) {
                if ($this->exists($alias)) {
                    $merge['valor_descuento'] = $this->input($alias);
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
        $negocioId = $this->user()?->negocio?->id;
        $tipoVentaId = (int) $this->route('id');
        $tipoDescuento = $this->input('tipo_descuento');
        $requiresValor = in_array($tipoDescuento, [
            TipoVenta::TIPO_PORCENTAJE,
            TipoVenta::TIPO_MONTO_FIJO,
        ], true);

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                Rule::unique('tb_tipos_venta', 'name')
                    ->where(fn ($q) => $q->where('negocio_id', $negocioId))
                    ->ignore($tipoVentaId),
            ],
            'tipo_descuento' => ['sometimes', 'required', 'string', Rule::in(TipoVenta::TIPOS_DESCUENTO)],
            'valor_descuento' => array_values(array_filter([
                $requiresValor ? 'required' : 'nullable',
                'numeric',
                'min:0',
                $tipoDescuento === TipoVenta::TIPO_PORCENTAJE ? 'max:100' : null,
            ])),
            'diferir_cobro' => ['sometimes', 'boolean'],
            'requiere_empleado' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre del tipo de venta es obligatorio.',
            'name.max' => 'El nombre no puede superar :max caracteres.',
            'name.unique' => 'Ya existe un tipo de venta con ese nombre en tu negocio.',
            'tipo_descuento.required' => 'El tipo de descuento es obligatorio.',
            'tipo_descuento.in' => 'Tipo de descuento inválido.',
            'valor_descuento.required' => 'El valor del descuento es obligatorio para este tipo.',
            'valor_descuento.min' => 'El valor del descuento no puede ser negativo.',
            'valor_descuento.max' => 'El porcentaje de descuento no puede superar 100.',
        ];
    }
}
