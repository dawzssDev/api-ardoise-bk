<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductoService
{
    private const IMAGE_DISK = 'productos';

    public function negocioForUser(User $user): Negocio
    {
        $negocio = $user->negocio;

        if (! $negocio) {
            throw new HttpException(422, 'El usuario no tiene un negocio asociado.');
        }

        return $negocio;
    }

    /**
     * @param  array{name: string, categoria_producto_id: int, price: float|int|string, image?: UploadedFile|null}  $data
     */
    public function create(Negocio $negocio, User $user, array $data): Producto
    {
        $imagePath = null;

        if (($data['image'] ?? null) instanceof UploadedFile) {
            $imagePath = $this->storeImage($negocio, $data['image']);
        }

        return $negocio->productos()->create([
            'categoria_producto_id' => $data['categoria_producto_id'],
            'name' => $data['name'],
            'price' => $data['price'],
            'image' => $imagePath,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    public function listForNegocio(Negocio $negocio, int $perPage = 15): LengthAwarePaginator
    {
        return $negocio->productos()
            ->with([
                'categoria:id,negocio_id,name',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->latest()
            ->paginate($perPage);
    }

    public function findForNegocio(Negocio $negocio, int $productoId): Producto
    {
        return $negocio->productos()
            ->with([
                'categoria:id,negocio_id,name',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ])
            ->findOrFail($productoId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Producto $producto, User $user, array $data): Producto
    {
        if (($data['image'] ?? null) instanceof UploadedFile) {
            $this->deleteImage($producto->image);
            $data['image'] = $this->storeImage($producto->negocio, $data['image']);
        } else {
            unset($data['image']);
        }

        $producto->fill($data);
        $producto->updated_by = $user->id;
        $producto->save();

        return $producto->refresh()->load([
            'categoria:id,negocio_id,name',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    public function delete(Producto $producto): void
    {
        $this->deleteImage($producto->image);
        $producto->delete();
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
