<?php

namespace App\Services;

use App\Models\CategoriaProducto;
use App\Models\Negocio;
use App\Models\Producto;
use App\Models\Staff;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductoService
{
    use ResolvesNegocioFromActor;

    private const IMAGE_DISK = 'productos';

    /**
     * @param  array{name: string, categoria_producto_id: int, price: float|int|string, image?: UploadedFile|null}  $data
     */
    public function create(Negocio $negocio, User|Staff $user, array $data): Producto
    {
        $this->assertCategoriaActiva($negocio, (int) $data['categoria_producto_id']);

        $imagePath = null;
        $auditId = $this->auditUserId($user, $negocio);

        if (($data['image'] ?? null) instanceof UploadedFile) {
            $imagePath = $this->storeImage($negocio, $data['image']);
        }

        return $negocio->productos()->create([
            'categoria_producto_id' => $data['categoria_producto_id'],
            'name' => $data['name'],
            'price' => $data['price'],
            'image' => $imagePath,
            'status' => Producto::STATUS_ACTIVO,
            'created_by' => $auditId,
            'updated_by' => $auditId,
        ]);
    }

    /**
     * Lista productos activos. Opcionalmente filtra por categoría.
     * Si $perPage es null, devuelve todos (una sola “página”).
     */
    public function listForNegocio(
        Negocio $negocio,
        ?int $perPage = null,
        ?int $categoriaProductoId = null,
    ): LengthAwarePaginator {
        $query = $negocio->productos()
            ->where('status', Producto::STATUS_ACTIVO)
            ->with([
                'categoria:id,negocio_id,name,status',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->latest('id');

        if ($categoriaProductoId !== null && $categoriaProductoId > 0) {
            $this->assertCategoriaBelongs($negocio, $categoriaProductoId);
            $query->where('categoria_producto_id', $categoriaProductoId);
        }

        if ($perPage === null || $perPage < 1) {
            $items = $query->get();
            $total = $items->count();

            return new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $total,
                max($total, 1),
                1,
            );
        }

        return $query->paginate(min($perPage, 500));
    }

    public function findForNegocio(Negocio $negocio, int $productoId): Producto
    {
        return $negocio->productos()
            ->with([
                'categoria:id,negocio_id,name,status',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->findOrFail($productoId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Producto $producto, User|Staff $user, array $data): Producto
    {
        if (array_key_exists('categoria_producto_id', $data)) {
            $this->assertCategoriaActiva($producto->negocio, (int) $data['categoria_producto_id']);
        }

        if (($data['image'] ?? null) instanceof UploadedFile) {
            $this->deleteImage($producto->image);
            $data['image'] = $this->storeImage($producto->negocio, $data['image']);
        } else {
            unset($data['image']);
        }

        unset($data['status']);

        $producto->fill($data);
        $producto->updated_by = $this->auditUserId($user, $producto->negocio);
        $producto->save();

        return $producto->refresh()->load([
            'categoria:id,negocio_id,name,status',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    /**
     * Baja lógica: status 1 → 0. No importa si está ligado a órdenes.
     */
    public function delete(Producto $producto, User|Staff $user): Producto
    {
        if ((int) $producto->status === Producto::STATUS_INACTIVO) {
            throw new HttpException(422, 'El producto ya está inactivo.');
        }

        $producto->status = Producto::STATUS_INACTIVO;
        $producto->updated_by = $this->auditUserId($user, $producto->negocio);
        $producto->save();

        return $producto->refresh()->load([
            'categoria:id,negocio_id,name,status',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    private function assertCategoriaActiva(Negocio $negocio, int $categoriaId): void
    {
        $exists = CategoriaProducto::query()
            ->where('negocio_id', $negocio->id)
            ->whereKey($categoriaId)
            ->where('status', CategoriaProducto::STATUS_ACTIVO)
            ->exists();

        if (! $exists) {
            throw new HttpException(422, 'La categoría seleccionada no existe, está inactiva o no pertenece a tu negocio.');
        }
    }

    private function assertCategoriaBelongs(Negocio $negocio, int $categoriaId): void
    {
        $exists = CategoriaProducto::query()
            ->where('negocio_id', $negocio->id)
            ->whereKey($categoriaId)
            ->where('status', CategoriaProducto::STATUS_ACTIVO)
            ->exists();

        if (! $exists) {
            throw new HttpException(422, 'La categoría no existe, está inactiva o no pertenece a tu negocio.');
        }
    }

    private function storeImage(Negocio $negocio, UploadedFile $file): string
    {
        return $file->store((string) $negocio->id, self::IMAGE_DISK);
    }

    private function deleteImage(?string $path): void
    {
        if (! $path) {
            return;
        }

        // Nuevo disco (public/productos)
        if (Storage::disk(self::IMAGE_DISK)->exists($path)) {
            Storage::disk(self::IMAGE_DISK)->delete($path);

            return;
        }

        // Rutas viejas en storage/app/public (antes de storage:link)
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
