<?php

namespace App\Services;

use App\Models\CategoriaInsumo;
use App\Models\Negocio;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CategoriaInsumoService
{
    use ResolvesNegocioFromActor;

    /**
     * @param  array{name: string}  $data
     */
    public function create(Negocio $negocio, array $data): CategoriaInsumo
    {
        return $negocio->categoriaInsumos()->create([
            'name' => $data['name'],
        ]);
    }

    public function listForNegocio(Negocio $negocio, int $perPage = 15): LengthAwarePaginator
    {
        return $negocio->categoriaInsumos()
            ->latest()
            ->paginate($perPage);
    }

    public function findForNegocio(Negocio $negocio, int $categoriaId): CategoriaInsumo
    {
        return $negocio->categoriaInsumos()->findOrFail($categoriaId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CategoriaInsumo $categoria, array $data): CategoriaInsumo
    {
        $categoria->fill($data);
        $categoria->save();

        return $categoria->refresh();
    }

    /**
     * Eliminar categoría solo si no tiene insumos ligados.
     */
    public function delete(CategoriaInsumo $categoria): void
    {
        if ($categoria->insumos()->exists()) {
            throw new HttpException(
                422,
                'No se puede eliminar la categoría porque tiene insumos ligados.',
            );
        }

        $categoria->delete();
    }
}
