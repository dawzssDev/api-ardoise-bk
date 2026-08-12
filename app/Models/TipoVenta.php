<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoVenta extends Model
{
    protected $table = 'tb_tipos_venta';

    public const TIPO_NINGUNO = 'ninguno';

    public const TIPO_PORCENTAJE = 'porcentaje';

    public const TIPO_MONTO_FIJO = 'monto_fijo';

    public const TIPO_GRATIS = 'gratis';

    public const TIPOS_DESCUENTO = [
        self::TIPO_NINGUNO,
        self::TIPO_PORCENTAJE,
        self::TIPO_MONTO_FIJO,
        self::TIPO_GRATIS,
    ];

    protected $fillable = [
        'negocio_id',
        'name',
        'tipo_descuento',
        'valor_descuento',
        'diferir_cobro',
        'requiere_empleado',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'valor_descuento' => 'decimal:2',
            'diferir_cobro' => 'boolean',
            'requiere_empleado' => 'boolean',
            'status' => 'boolean',
        ];
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function ordenDetalles(): HasMany
    {
        return $this->hasMany(OrdenDetalle::class, 'tipo_venta_id');
    }

    /**
     * Precio final unitario a partir del precio de lista.
     */
    public function applyDiscount(float $precioLista): float
    {
        return match ($this->tipo_descuento) {
            self::TIPO_PORCENTAJE => max(
                0,
                $precioLista * (1 - ((float) $this->valor_descuento / 100))
            ),
            self::TIPO_MONTO_FIJO => max(0, $precioLista - (float) $this->valor_descuento),
            self::TIPO_GRATIS => 0.0,
            default => $precioLista,
        };
    }
}
