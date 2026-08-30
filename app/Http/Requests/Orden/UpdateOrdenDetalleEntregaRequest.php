<?php

namespace App\Http\Requests\Orden;

use App\Models\OrdenDetalle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrdenDetalleEntregaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('status_entregado')) {
            return;
        }

        foreach (['statusEntregado', 'estatus_entregado', 'entrega_status'] as $alias) {
            if ($this->exists($alias)) {
                $this->merge(['status_entregado' => $this->input($alias)]);

                return;
            }
        }

        if ($this->exists('entregado')) {
            $this->merge([
                'status_entregado' => $this->boolean('entregado')
                    ? OrdenDetalle::ENTREGA_ENTREGADO
                    : OrdenDetalle::ENTREGA_SIN_ENTREGAR,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status_entregado' => ['required', 'integer', Rule::in(OrdenDetalle::ENTREGA_STATUSES)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status_entregado.required' => 'El estatus de entrega es obligatorio.',
            'status_entregado.in' => 'Estatus de entrega inválido. Usa 1 (sin entregar) o 2 (entregado).',
        ];
    }
}
