<?php

namespace App\Services;

use App\Models\Insumo;
use App\Models\Negocio;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\Producto;
use App\Models\StockInsumo;
use App\Models\StockProducto;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DescuentoStockVentaService
{
    /**
     * Valida y descuenta stock de productos/insumos al cobrar.
     * Si ningún producto vendido tiene receta, no toca stock (flujo actual).
     */
    public function consumirPorOrden(Orden $orden, Negocio $negocio, ?int $auditUserId = null): void
    {
        $this->consumirPorVenta(
            $negocio,
            (int) $orden->sucursal_id,
            $orden->detalles()->get(['producto_id', 'quantity', 'status']),
            $auditUserId,
        );
    }

    /**
     * @param  iterable<array<string, mixed>|object>  $lineas
     */
    public function consumirPorVenta(
        Negocio $negocio,
        int $sucursalId,
        iterable $lineas,
        ?int $auditUserId = null,
    ): void {
        [$productos, $insumos] = $this->agregarRequeridos($negocio, $lineas);

        if ($productos === [] && $insumos === []) {
            return;
        }

        if ($productos !== []) {
            $this->descontarStockProductos($negocio, $sucursalId, $productos, $auditUserId);
        }

        if ($insumos !== []) {
            $this->descontarStockInsumos($negocio, $sucursalId, $insumos, $auditUserId);
        }
    }

    /**
     * @param  iterable<array<string, mixed>|object>  $lineas
     * @return array{0: array<int, array{name: string, cantidad: float}>, 1: array<int, array{name: string, cantidad: float}>}
     */
    private function agregarRequeridos(Negocio $negocio, iterable $lineas): array
    {
        $cantidadesPorProducto = [];
        foreach ($lineas as $linea) {
            $status = (int) $this->lineaValue($linea, 'status', OrdenDetalle::STATUS_PENDIENTE);
            if ($status === OrdenDetalle::STATUS_CANCELADO) {
                continue;
            }

            $productoId = (int) $this->lineaValue($linea, 'producto_id', 0);
            $cantidadVendida = (float) $this->lineaValue($linea, 'quantity', 0);
            if ($productoId <= 0 || $cantidadVendida <= 0) {
                continue;
            }

            $cantidadesPorProducto[$productoId] = round(
                ($cantidadesPorProducto[$productoId] ?? 0) + $cantidadVendida,
                3,
            );
        }

        if ($cantidadesPorProducto === []) {
            return [[], []];
        }

        $catalogo = $negocio->productos()
            ->whereIn('id', array_keys($cantidadesPorProducto))
            ->get(['id', 'name', 'descuento_stock_prod', 'descuento_stock_insum'])
            ->keyBy('id');

        $productos = [];
        $insumos = [];

        foreach ($cantidadesPorProducto as $productoId => $cantidadVendida) {
            /** @var Producto|null $producto */
            $producto = $catalogo->get($productoId);
            if (! $producto) {
                continue;
            }

            foreach ($this->itemsReceta($producto->descuento_stock_prod, 'Producto') as $item) {
                $this->acumular($productos, $item['id'], $item['name'], $item['cantidad'] * $cantidadVendida);
            }

            foreach ($this->itemsReceta($producto->descuento_stock_insum, 'Insumo') as $item) {
                $this->acumular($insumos, $item['id'], $item['name'], $item['cantidad'] * $cantidadVendida);
            }
        }

        return [$productos, $insumos];
    }

    /**
     * @return list<array{id: int, name: string, cantidad: float}>
     */
    private function itemsReceta(mixed $value, string $nameKey): array
    {
        if (! is_array($value) || $value === []) {
            return [];
        }

        $items = [];
        foreach (array_is_list($value) ? $value : array_values($value) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? $item['producto_id'] ?? $item['insumo_id'] ?? null;
            $cantidad = $item['cantidad'] ?? $item['Cantidad'] ?? $item['quantity'] ?? null;
            if ($id === null || $id === '' || $cantidad === null || (float) $cantidad <= 0) {
                continue;
            }

            $name = trim((string) ($item[$nameKey] ?? $item['name'] ?? $item['nombre'] ?? ''));
            $items[] = [
                'id' => (int) $id,
                'name' => $name,
                'cantidad' => (float) $cantidad,
            ];
        }

        return $items;
    }

    /**
     * @param  array<int, array{name: string, cantidad: float}>  $bucket
     */
    private function acumular(array &$bucket, int $id, string $name, float $cantidad): void
    {
        if ($id <= 0 || $cantidad <= 0) {
            return;
        }

        if (! isset($bucket[$id])) {
            $bucket[$id] = [
                'name' => $name,
                'cantidad' => 0.0,
            ];
        } elseif ($bucket[$id]['name'] === '' && $name !== '') {
            $bucket[$id]['name'] = $name;
        }

        $bucket[$id]['cantidad'] = round($bucket[$id]['cantidad'] + $cantidad, 3);
    }

    /**
     * @param  array<int, array{name: string, cantidad: float}>  $requeridos
     */
    private function descontarStockProductos(
        Negocio $negocio,
        int $sucursalId,
        array $requeridos,
        ?int $auditUserId,
    ): void {
        $ids = array_keys($requeridos);
        sort($ids);

        $stocks = $negocio->stockProductos()
            ->where('sucursal_id', $sucursalId)
            ->whereIn('producto_id', $ids)
            ->lockForUpdate()
            ->get()
            ->keyBy('producto_id');

        $nombres = Producto::query()
            ->where('negocio_id', $negocio->id)
            ->whereIn('id', $ids)
            ->pluck('name', 'id');

        foreach ($ids as $productoId) {
            $requerido = $requeridos[$productoId]['cantidad'];
            $nombre = $requeridos[$productoId]['name'] !== ''
                ? $requeridos[$productoId]['name']
                : (string) ($nombres[$productoId] ?? "producto #{$productoId}");

            /** @var StockProducto|null $stock */
            $stock = $stocks->get($productoId);
            $this->assertStockSuficiente($stock?->stock_fisico, $requerido, 'producto', $nombre);

            $stock->stock_fisico = round((float) $stock->stock_fisico - $requerido, 3);
            if ($auditUserId !== null) {
                $stock->updated_by = $auditUserId;
            }
            $stock->save();
        }
    }

    /**
     * @param  array<int, array{name: string, cantidad: float}>  $requeridos
     */
    private function descontarStockInsumos(
        Negocio $negocio,
        int $sucursalId,
        array $requeridos,
        ?int $auditUserId,
    ): void {
        $ids = array_keys($requeridos);
        sort($ids);

        $stocks = $negocio->stockInsumos()
            ->where('sucursal_id', $sucursalId)
            ->whereIn('insumo_id', $ids)
            ->lockForUpdate()
            ->get()
            ->keyBy('insumo_id');

        $nombres = Insumo::query()
            ->where('negocio_id', $negocio->id)
            ->whereIn('id', $ids)
            ->pluck('name', 'id');

        foreach ($ids as $insumoId) {
            $requerido = $requeridos[$insumoId]['cantidad'];
            $nombre = $requeridos[$insumoId]['name'] !== ''
                ? $requeridos[$insumoId]['name']
                : (string) ($nombres[$insumoId] ?? "insumo #{$insumoId}");

            /** @var StockInsumo|null $stock */
            $stock = $stocks->get($insumoId);
            $this->assertStockSuficiente($stock?->stock_fisico, $requerido, 'insumo', $nombre);

            $stock->stock_fisico = round((float) $stock->stock_fisico - $requerido, 3);
            if ($auditUserId !== null) {
                $stock->updated_by = $auditUserId;
            }
            $stock->save();
        }
    }

    private function assertStockSuficiente(
        mixed $stockFisico,
        float $requerido,
        string $tipo,
        string $nombre,
    ): void {
        if ($stockFisico === null) {
            throw new HttpException(
                422,
                "No hay stock de {$tipo} {$nombre} en esta sucursal para completar la venta.",
            );
        }

        $disponible = round((float) $stockFisico, 3);
        if (round($disponible - $requerido, 3) < 0) {
            throw new HttpException(
                422,
                "No hay stock suficiente de {$tipo} {$nombre}. Disponible: "
                .number_format($disponible, 3, '.', '')
                .', requerido: '
                .number_format($requerido, 3, '.', '')
                .'.',
            );
        }
    }

    private function lineaValue(array|object $linea, string $key, mixed $default): mixed
    {
        if (is_array($linea)) {
            return $linea[$key] ?? $default;
        }

        return $linea->{$key} ?? $default;
    }
}
