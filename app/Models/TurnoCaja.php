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
        'total_retiros_efectivo',
        'total_depositos_efectivo',
        'efectivo_esperado',
        'efectivo_real',
        'efectivo_real_cajera',
        'diferencia',
        'status',
        'status_administrador',
        'status_gerencia',
        'fecha_apertura',
        'fecha_cierre',
        'fecha_cierre_cajera',
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
            'total_retiros_efectivo' => 'decimal:2',
            'total_depositos_efectivo' => 'decimal:2',
            'efectivo_esperado' => 'decimal:2',
            'efectivo_real' => 'decimal:2',
            'efectivo_real_cajera' => 'decimal:2',
            'diferencia' => 'decimal:2',
            'fecha_apertura' => 'datetime',
            'fecha_cierre' => 'datetime',
            'fecha_cierre_cajera' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_ABIERTO;
    }

    public function isAdminOpen(): bool
    {
        return $this->status_administrador === self::STATUS_ABIERTO;
    }

    public function isGerenciaOpen(): bool
    {
        return $this->status_gerencia === self::STATUS_ABIERTO;
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

    public function gastos(): HasMany
    {
        return $this->hasMany(GastoEnTurno::class, 'turno_caja_id');
    }

    public function depositos(): HasMany
    {
        return $this->hasMany(DepositoEnTurno::class, 'turno_caja_id');
    }
}
