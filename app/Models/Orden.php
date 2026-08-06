<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Orden extends Model
{
    protected $table = 'ordenes';

    public const PAYMENT_TYPES = ['credito', 'tarjeta', 'transferencia', 'efectivo'];

    /** Pendiente de cobro */
    public const STATUS_PENDIENTE = 1;

    /** Cobrado en POS */
    public const STATUS_PAGADA = 2;

    /** Cancelada */
    public const STATUS_CANCELADA = 3;

    /** Cocina avanzó la orden */
    public const STATUS_EN_COCINA = 4;

    /** Cocina marcó la orden lista */
    public const STATUS_LISTA = 5;

    /** Orden entregada / finalizada */
    public const STATUS_ENTREGADA = 6;

    public const STATUSES = [
        self::STATUS_PENDIENTE,
        self::STATUS_PAGADA,
        self::STATUS_CANCELADA,
        self::STATUS_EN_COCINA,
        self::STATUS_LISTA,
        self::STATUS_ENTREGADA,
    ];

    /** Estatus donde cocina “avanza” la orden */
    public const ADVANCE_STATUSES = [
        self::STATUS_EN_COCINA,
    ];

    /** Estatus donde cocina “finaliza” la orden */
    public const FINISH_STATUSES = [
        self::STATUS_LISTA,
        self::STATUS_ENTREGADA,
    ];

    protected $fillable = [
        'order_number',
        'negocio_id',
        'sucursal_id',
        'customer_name',
        'payment_type',
        'total',
        'status',
        'created_by_staff_id',
        'advanced_by_staff_id',
        'finished_by_staff_id',
        'advanced_at',
        'finished_at',
        'preparacion_started_at',
        'listo_at',
        'seconds_in_nuevo',
        'seconds_in_preparacion',
        'seconds_total_listo',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'order_number' => 'integer',
            'total' => 'decimal:2',
            'status' => 'integer',
            'advanced_at' => 'datetime',
            'finished_at' => 'datetime',
            'preparacion_started_at' => 'datetime',
            'listo_at' => 'datetime',
            'seconds_in_nuevo' => 'integer',
            'seconds_in_preparacion' => 'integer',
            'seconds_total_listo' => 'integer',
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

    public function detalles(): HasMany
    {
        return $this->hasMany(OrdenDetalle::class);
    }

    public function createdByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'created_by_staff_id');
    }

    public function advancedByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'advanced_by_staff_id');
    }

    public function finishedByStaff(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'finished_by_staff_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * NumeroOrden con 6 dígitos: 000001
     */
    public function numeroOrden(): string
    {
        return str_pad((string) $this->order_number, 6, '0', STR_PAD_LEFT);
    }
}
