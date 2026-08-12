<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuentaPorCobrar extends Model
{
    protected $table = 'tb_cuentas_por_cobrar';

    public const STATUS_PENDIENTE = 'pendiente';

    public const STATUS_PAGADO = 'pagado';

    public const STATUSES = [
        self::STATUS_PENDIENTE,
        self::STATUS_PAGADO,
    ];

    protected $fillable = [
        'negocio_id',
        'sucursal_id',
        'empleado_id',
        'orden_id',
        'orden_detalle_id',
        'turno_caja_id',
        'concepto',
        'monto',
        'status',
        'fecha_generado',
        'fecha_pagado',
        'pagado_por',
        'nota',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_generado' => 'datetime',
            'fecha_pagado' => 'datetime',
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

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(Orden::class);
    }

    public function ordenDetalle(): BelongsTo
    {
        return $this->belongsTo(OrdenDetalle::class, 'orden_detalle_id');
    }

    public function turnoCaja(): BelongsTo
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_caja_id');
    }

    public function pagadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pagado_por');
    }
}
