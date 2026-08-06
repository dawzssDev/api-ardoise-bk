<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Venta extends Model
{
    protected $table = 'tb_ventas';

    protected $fillable = [
        'turno_caja_id',
        'id_user',
        'orden_id',
        'order_number',
        'payment_type',
        'total',
        'sucursal_id',
        'negocio_id',
        'fecha_venta',
    ];

    protected function casts(): array
    {
        return [
            'order_number' => 'integer',
            'total' => 'decimal:2',
            'fecha_venta' => 'datetime',
        ];
    }

    public function turnoCaja(): BelongsTo
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_caja_id');
    }

    public function cajera(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'id_user');
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(Orden::class, 'orden_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }
}
