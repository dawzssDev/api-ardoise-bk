<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Producto extends Model
{
    protected $table = 'productos';

    protected $fillable = [
        'negocio_id',
        'categoria_producto_id',
        'name',
        'price',
        'image',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaProducto::class, 'categoria_producto_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        $path = $this->image;

        // Compatibilidad con rutas viejas: productos/1/archivo.png
        if (str_starts_with($path, 'productos/')) {
            $path = substr($path, strlen('productos/'));
        }

        $parts = explode('/', $path, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return route('productos.image', [
            'negocioId' => $parts[0],
            'filename' => $parts[1],
        ]);
    }
}
