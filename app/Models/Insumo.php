<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Insumo extends Model
{
    protected $table = 'insumos';

    protected $fillable = [
        'negocio_id',
        'categoria_insumo_id',
        'name',
        'status_insumo',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status_insumo' => 'boolean',
        ];
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaInsumo::class, 'categoria_insumo_id');
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
