<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockInsumo extends Model
{
    protected $table = 'stock_insumos';

    protected $fillable = [
        'negocio_id',
        'sucursal_id',
        'insumo_id',
        'stock_fisico',
        'stock_minimo',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'stock_fisico' => 'decimal:3',
            'stock_minimo' => 'decimal:3',
        ];
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function insumo(): BelongsTo
    {
        return $this->belongsTo(Insumo::class);
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
