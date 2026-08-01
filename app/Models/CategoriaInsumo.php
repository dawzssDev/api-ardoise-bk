<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaInsumo extends Model
{
    protected $table = 'categoria_insumos';

    protected $fillable = [
        'negocio_id',
        'name',
    ];

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function insumos(): HasMany
    {
        return $this->hasMany(Insumo::class, 'categoria_insumo_id');
    }
}
