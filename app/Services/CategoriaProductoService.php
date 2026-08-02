<?php

namespace App\Services;

use App\Models\CategoriaProducto;
use App\Models\Negocio;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CategoriaProductoService
{
    public function negocioForUser(User $user): Negocio
    {
        $negocio = $user->negocio;

        if (! $negocio) {
            throw new HttpException(422, 'El usuario no tiene un negocio asociado.');
        }

        return $negocio;
    }

    /**
     * @param  array{name: string}  $data
     */
    public function create(Negocio $negocio, array $data): CategoriaProducto
    {
        return $negocio->categoriaProductos()->create([
            'name' => $data['name'],
        ]);
    }

    public function listForNegocio(Negocio $negocio, int $perPage = 15): LengthAwarePaginator
    {
        return $negocio->categoriaProductos()
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
     * Eliminar categoría solo si no tiene productos ligados.
     */
    public function delete(CategoriaProducto $categoria): void
    {
        if ($categoria->productos()->exists()) {
            throw new HttpException(
                422,
                'No se puede eliminar la categoría porque tiene productos ligados.',
            );
        }

        $categoria->delete();
    }
}
