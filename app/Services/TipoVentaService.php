<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\TipoVenta;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TipoVentaService
{
    use ResolvesNegocioFromActor;

    /**
     * @param  array{
     *     name: string,
     *     tipo_descuento: string,
     *     valor_descuento?: float|int|string|null,
     *     diferir_cobro?: bool,
     *     requiere_empleado?: bool,
     *     require_autori?: int,
     *     status?: bool
     * }  $data
     */
    public function create(Negocio $negocio, array $data): TipoVenta
    {
        return $negocio->tiposVenta()->create([
            'name' => $data['name'],
            'tipo_descuento' => $data['tipo_descuento'],
            'valor_descuento' => $this->normalizeValorDescuento(
                $data['tipo_descuento'],
                $data['valor_descuento'] ?? null,
            ),
            'diferir_cobro' => array_key_exists('diferir_cobro', $data)
                ? (bool) $data['diferir_cobro']
                : false,
            'requiere_empleado' => array_key_exists('requiere_empleado', $data)
                ? (bool) $data['requiere_empleado']
                : false,
            'require_autori' => array_key_exists('require_autori', $data)
                ? (int) $data['require_autori']
                : 0,
            'status' => array_key_exists('status', $data) ? (bool) $data['status'] : true,
        ]);
    }

    public function listForNegocio(Negocio $negocio, int $perPage = 15): LengthAwarePaginator
    {
        return $negocio->tiposVenta()
            ->latest()
            ->paginate($perPage);
    }

    public function findForNegocio(Negocio $negocio, int $tipoVentaId): TipoVenta
    {
        return $negocio->tiposVenta()->findOrFail($tipoVentaId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(TipoVenta $tipoVenta, array $data): TipoVenta
    {
        if (array_key_exists('tipo_descuento', $data) || array_key_exists('valor_descuento', $data)) {
            $tipoDescuento = $data['tipo_descuento'] ?? $tipoVenta->tipo_descuento;
            $valor = array_key_exists('valor_descuento', $data)
                ? $data['valor_descuento']
                : $tipoVenta->valor_descuento;
            $data['valor_descuento'] = $this->normalizeValorDescuento($tipoDescuento, $valor);
        }

        if (array_key_exists('require_autori', $data)) {
            $data['require_autori'] = (int) $data['require_autori'];
        }

        $tipoVenta->fill($data);
        $tipoVenta->save();

        return $tipoVenta->refresh();
    }

    /**
     * Eliminar tipo de venta solo si no está ligado a detalles de orden.
     */
    public function delete(TipoVenta $tipoVenta): void
    {
        if ($tipoVenta->ordenDetalles()->exists()) {
            throw new HttpException(
                422,
                'No se puede eliminar el tipo de venta porque está ligado a órdenes.',
            );
        }

        $tipoVenta->delete();
    }

    private function normalizeValorDescuento(string $tipoDescuento, mixed $valor): ?string
    {
        if (in_array($tipoDescuento, [TipoVenta::TIPO_NINGUNO, TipoVenta::TIPO_GRATIS], true)) {
            return null;
        }

        return $valor === null ? null : number_format((float) $valor, 2, '.', '');
    }
}
