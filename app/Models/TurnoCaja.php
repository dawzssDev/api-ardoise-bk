<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TurnoCaja extends Model
{
    protected $table = 'tb_turnos_cajas';

    public const STATUS_ABIERTO = 'abierto';

    public const STATUS_CERRADO = 'cerrado';

    public const STATUSES = [
        self::STATUS_ABIERTO,
        self::STATUS_CERRADO,
    ];

    protected $fillable = [
        'id_user',
        'user_id',
        'negocio_id',
        'sucursal_id',
        'fondo_inicial',
        'total_ventas_efectivo',
        'total_ventas_tarjeta',
        'total_ventas_transferencia',
        'total_ventas',
        'total_pagos_proveedores',
        'total_gastos_operativos',
        'efectivo_esperado',
        'efectivo_real',
        'diferencia',
        'status',
        'fecha_apertura',
        'fecha_cierre',
        'observaciones_cierre',
    ];

    protected function casts(): array
    {
        return [
            'fondo_inicial' => 'decimal:2',
            'total_ventas_efectivo' => 'decimal:2',
            'total_ventas_tarjeta' => 'decimal:2',
            'total_ventas_transferencia' => 'decimal:2',
            'total_ventas' => 'decimal:2',
            'total_pagos_proveedores' => 'decimal:2',
            'total_gastos_operativos' => 'decimal:2',
            'efectivo_esperado' => 'decimal:2',
            'efectivo_real' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'fecha_apertura' => 'datetime',
            'fecha_cierre' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_ABIERTO;
    }

    public function cajera(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'id_user');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function ventas(): HasMany
    {
        return $this->hasMany(Venta::class, 'turno_caja_id');
    }
}
