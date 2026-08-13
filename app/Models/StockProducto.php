<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockProducto extends Model
{
    protected $table = 'stock_productos';

    protected $fillable = [
        'negocio_id',
        'sucursal_id',
        'producto_id',
        'stock_fisico',
        'stock_minimo',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'stock_fisico' => 'decimal:3',
            'stock_minimo' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public static function normalizeActivo(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        $normalized = mb_strtolower(trim((string) $value));
        $normalized = strtr($normalized, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        return match ($normalized) {
            '1', 'true', 'on', 'yes', 'si', 'activo', 'activa', 'disponible' => true,
            '0', 'false', 'off', 'no', 'inactivo', 'inactiva', 'no disponible', 'no_disponible', 'nodisponible' => false,
            default => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
        };
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
