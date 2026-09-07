<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sucursal extends Model
{
    public const TYPE_SUCURSAL = 'sucursal';

    public const TYPE_BODEGA = 'bodega';

    public const TYPES = [
        self::TYPE_SUCURSAL,
        self::TYPE_BODEGA,
    ];

    protected $table = 'sucursales';

    protected $fillable = [
        'negocio_id',
        'type',
        'name',
        'is_active',
        'street',
        'neighborhood',
        'city',
        'state',
        'postal_code',
        'opened_year',
        'monto_maximo_efectivo',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'opened_year' => 'integer',
            'monto_maximo_efectivo' => 'decimal:2',
        ];
    }

    public function montoMaximoEfectivo(): ?float
    {
        if ($this->monto_maximo_efectivo === null) {
            return null;
        }

        $monto = round((float) $this->monto_maximo_efectivo, 2);

        return $monto > 0 ? $monto : null;
    }

    public function excedeMontoMaximoEfectivo(float $efectivoEnCaja): bool
    {
        $maximo = $this->montoMaximoEfectivo();
        if ($maximo === null) {
            return false;
        }

        return $efectivoEnCaja - $maximo > 0.009;
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function stockInsumos(): HasMany
    {
        return $this->hasMany(StockInsumo::class);
    }

    public function stockProductos(): HasMany
    {
        return $this->hasMany(StockProducto::class);
    }

    public function cuentasContables(): HasMany
    {
        return $this->hasMany(MaeCuentaContaSuc::class);
    }
}
