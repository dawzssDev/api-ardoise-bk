<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrdenDetalle extends Model
{
    protected $table = 'orden_detalles';

    public const STATUS_PENDIENTE = 1;

    public const STATUS_EN_PREPARACION = 2;

    public const STATUS_LISTO = 3;

    public const STATUS_ENTREGADO = 4;

    public const STATUS_CANCELADO = 5;

    public const STATUSES = [
        self::STATUS_PENDIENTE,
        self::STATUS_EN_PREPARACION,
        self::STATUS_LISTO,
        self::STATUS_ENTREGADO,
        self::STATUS_CANCELADO,
    ];

    public const ENTREGA_SIN_ENTREGAR = 1;

    public const ENTREGA_ENTREGADO = 2;

    public const ENTREGA_STATUSES = [
        self::ENTREGA_SIN_ENTREGAR,
        self::ENTREGA_ENTREGADO,
    ];

    public const ADVANCE_STATUSES = [
        self::STATUS_EN_PREPARACION,
    ];

    public const FINISH_STATUSES = [
        self::STATUS_LISTO,
        self::STATUS_ENTREGADO,
    ];

    protected $fillable = [
        'orden_id',
        'producto_id',
        'tipo_venta_id',
        'product_name',
        'quantity',
        'precio_lista',
        'diferido',
        'empleado_id',
        'price',
        'extras',
        'notes',
        'status',
        'status_entregado',
        'advanced_by_staff_id',
        'finished_by_staff_id',
        'advanced_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'precio_lista' => 'decimal:2',
            'diferido' => 'boolean',
            'price' => 'decimal:2',
            'extras' => 'array',
            'status' => 'integer',
            'status_entregado' => 'integer',
            'advanced_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(Orden::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function tipoVenta(): BelongsTo
    {
        return $this->belongsTo(TipoVenta::class, 'tipo_venta_id');
    }

    public function empleado(): BelongsTo
    {
        return $this->belongsTo(Empleado::class);
    }

    public function advancedByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'advanced_by_staff_id');
    }

    public function finishedByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'finished_by_staff_id');
    }

    public function lineTotal(): string
    {
        return number_format((float) $this->quantity * (float) $this->price, 2, '.', '');
    }
}
