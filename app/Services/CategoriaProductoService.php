<?php

namespace App\Services;

use App\Models\CategoriaProducto;
use App\Models\Negocio;
use App\Models\Producto;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CategoriaProductoService
{
    use ResolvesNegocioFromActor;

    /**
     * @param  array{name: string}  $data
     */
    public function create(Negocio $negocio, array $data): CategoriaProducto
    {
        return $negocio->categoriaProductos()->create([
            'name' => $data['name'],
            'status' => CategoriaProducto::STATUS_ACTIVO,
        ]);
    }

    public function listForNegocio(Negocio $negocio, int $perPage = 15): LengthAwarePaginator
    {
        return $negocio->categoriaProductos()
            ->where('status', CategoriaProducto::STATUS_ACTIVO)
            ->latest()
            ->paginate($perPage);
    }

    public function findForNegocio(Negocio $negocio, int $categoriaId): CategoriaProducto
    {
        return $negocio->categoriaProductos()->findOrFail($categoriaId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CategoriaProducto $categoria, array $data): CategoriaProducto
    {
        $categoria->fill($data);
        $categoria->save();

        return $categoria->refresh();
    }

    /**
     * Baja lógica: status 1 → 0.
     * Solo si no tiene productos activos ligados.
     */
    public function delete(CategoriaProducto $categoria): CategoriaProducto
    {
        if ((int) $categoria->status === CategoriaProducto::STATUS_INACTIVO) {
            throw new HttpException(422, 'La categoría ya está inactiva.');
        }

        $productosActivos = $categoria->productos()
            ->where('status', Producto::STATUS_ACTIVO)
            ->count();

        if ($productosActivos > 0) {
            throw new HttpException(
                422,
                $productosActivos === 1
                    ? 'No se puede eliminar la categoría: tienes 1 producto en esta categoría. Da de baja o reasigna ese producto primero.'
                    : "No se puede eliminar la categoría: tienes {$productosActivos} productos en esta categoría. Da de baja o reasigna esos productos primero.",
            );
        }

        $categoria->status = CategoriaProducto::STATUS_INACTIVO;
        $categoria->save();

        return $categoria->refresh();
    }
}
