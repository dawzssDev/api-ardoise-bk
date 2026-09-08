<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TurnoCajaCorte extends Model
{
    protected $table = 'tb_turnos_cajas_cortes';

    public const TIPO_PARCIAL = 1;

    public const TIPO_CIERRE = 2;

    /**
     * @var array<int, string>
     */
    public const TIPO_LABELS = [
        self::TIPO_PARCIAL => 'parcial',
        self::TIPO_CIERRE => 'cierre',
    ];

    protected $fillable = [
        'turno_caja_id',
        'id_user',
        'user_id',
        'negocio_id',
        'sucursal_id',
        'total_ventas_efectivo',
        'total_ventas_tarjeta',
        'total_ventas_transferencia',
        'total_ventas',
        'total_pagos_proveedores',
        'total_gastos_operativos',
        'total_retiros_efectivo',
        'total_depositos_efectivo',
        'total_pagos_con_deposito',
        'efectivo_real_cajera',
        'tipo_corte',
        'fecha_cierre_cajera',
        'observaciones_cierre',
    ];

    protected function casts(): array
    {
        return [
            'total_ventas_efectivo' => 'decimal:2',
            'total_ventas_tarjeta' => 'decimal:2',
            'total_ventas_transferencia' => 'decimal:2',
            'total_ventas' => 'decimal:2',
            'total_pagos_proveedores' => 'decimal:2',
            'total_gastos_operativos' => 'decimal:2',
            'total_retiros_efectivo' => 'decimal:2',
            'total_depositos_efectivo' => 'decimal:2',
            'total_pagos_con_deposito' => 'decimal:2',
            'efectivo_real_cajera' => 'decimal:2',
            'tipo_corte' => 'integer',
            'fecha_cierre_cajera' => 'datetime',
        ];
    }

    public function isParcial(): bool
    {
        return (int) $this->tipo_corte === self::TIPO_PARCIAL;
    }

    public function isCierre(): bool
    {
        return (int) $this->tipo_corte === self::TIPO_CIERRE;
    }

    /**
     * Efectivo a cotejar en este tramo (sin fondo): ventas + depósitos − gastos.
     */
    public function efectivoEsperado(): float
    {
        return round(
            (float) $this->total_ventas_efectivo
            + (float) $this->total_depositos_efectivo
            - (float) $this->total_pagos_proveedores
            - (float) $this->total_gastos_operativos
            - (float) $this->total_retiros_efectivo,
            2
        );
    }

    public function diferencia(): float
    {
        return round($this->efectivoEsperado() - (float) $this->efectivo_real_cajera, 2);
    }

    public static function labelForTipo(int $tipo): string
    {
        return self::TIPO_LABELS[$tipo] ?? (string) $tipo;
    }

    public function turnoCaja(): BelongsTo
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_caja_id');
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
}
