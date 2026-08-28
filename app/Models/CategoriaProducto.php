<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaProducto extends Model
{
    protected $table = 'categoria_productos';

    public const STATUS_INACTIVO = 0;

    public const STATUS_ACTIVO = 1;

    protected $fillable = [
        'negocio_id',
        'name',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
        ];
    }

    public function isActivo(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVO;
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class, 'categoria_producto_id');
    }
}
