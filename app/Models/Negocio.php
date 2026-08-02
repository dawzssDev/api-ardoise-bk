<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Negocio extends Model
{
    protected $table = 'negocios';

    protected $fillable = [
        'user_id',
        'name',
        'phone',
        'needs_invoice',
        'rfc',
        'legal_name',
        'tax_regime',
        'tax_zip',
        'cfdi_use',
    ];

    protected function casts(): array
    {
        return [
            'needs_invoice' => 'boolean',
        ];
    }

    public function masterUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sucursales(): HasMany
    {
        return $this->hasMany(Sucursal::class);
    }

    public function categoriaInsumos(): HasMany
    {
        return $this->hasMany(CategoriaInsumo::class);
    }

    public function categoriaProductos(): HasMany
    {
        return $this->hasMany(CategoriaProducto::class);
    }

    public function productos(): HasMany
    {
        return $this->hasMany(Producto::class);
    }

    public function insumos(): HasMany
    {
        return $this->hasMany(Insumo::class);
    }

    public function stockInsumos(): HasMany
    {
        return $this->hasMany(StockInsumo::class);
    }
}
