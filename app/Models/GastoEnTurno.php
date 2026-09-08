<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GastoEnTurno extends Model
{
    protected $table = 'tb_gastos_en_turno';

    public const TIPO_PAGO_PROVEEDOR = 'pago_proveedor';

    public const TIPO_GASTO_OPERATIVO = 'gasto_operativo';

    public const TIPO_RETIRO_EFECTIVO = 'retiro_efectivo';

    public const TIPOS = [
        self::TIPO_PAGO_PROVEEDOR,
        self::TIPO_GASTO_OPERATIVO,
        self::TIPO_RETIRO_EFECTIVO,
    ];

    public const ORIGEN_VENTA = 'venta';

    public const ORIGEN_DEPOSITO = 'deposito';

    public const ORIGENES = [
        self::ORIGEN_VENTA,
        self::ORIGEN_DEPOSITO,
    ];

    /**
     * @var array<string, string>
     */
    public const TIPO_LABELS = [
        self::TIPO_PAGO_PROVEEDOR => 'Pago proveedor',
        self::TIPO_GASTO_OPERATIVO => 'Gasto operativo',
        self::TIPO_RETIRO_EFECTIVO => 'Retiro de efectivo',
    ];

    /**
     * @var array<string, string>
     */
    public const ORIGEN_LABELS = [
        self::ORIGEN_VENTA => 'Descuento a venta',
        self::ORIGEN_DEPOSITO => 'Descuento a depósitos',
    ];

    protected $fillable = [
        'turno_caja_id',
        'id_user',
        'user_id',
        'negocio_id',
        'sucursal_id',
        'tipo_gasto',
        'proveedor_id',
        'origen',
        'descripcion',
        'monto',
        'fecha_registro',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_registro' => 'datetime',
        ];
    }

    public static function normalizeTipo(mixed $tipo): ?string
    {
        if (! is_string($tipo) && ! is_numeric($tipo)) {
            return null;
        }

        $raw = trim((string) $tipo);
        if ($raw === '') {
            return null;
        }

        if (in_array($raw, self::TIPOS, true)) {
            return $raw;
        }

        $key = mb_strtolower($raw);
        $key = strtr($key, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
        ]);
        $key = preg_replace('/[\s\-]+/', '_', $key) ?? $key;
        $key = str_replace(['pago_a_proveedor', 'pagoproveedor'], 'pago_proveedor', $key);
        $key = str_replace(['gastooperativo'], 'gasto_operativo', $key);
        $key = str_replace(['retirodeefectivo', 'retiro_de_efectivo'], 'retiro_efectivo', $key);

        $aliases = [
            'pago_proveedor' => self::TIPO_PAGO_PROVEEDOR,
            'pagoproveedor' => self::TIPO_PAGO_PROVEEDOR,
            'gasto_operativo' => self::TIPO_GASTO_OPERATIVO,
            'gastooperativo' => self::TIPO_GASTO_OPERATIVO,
            'retiro_efectivo' => self::TIPO_RETIRO_EFECTIVO,
            'retiroefectivo' => self::TIPO_RETIRO_EFECTIVO,
        ];

        return $aliases[$key] ?? null;
    }

    public static function labelFor(string $tipo): string
    {
        return self::TIPO_LABELS[$tipo] ?? $tipo;
    }

    public static function normalizeOrigen(mixed $origen): ?string
    {
        if ($origen === null) {
            return null;
        }

        if (! is_string($origen) && ! is_numeric($origen)) {
            return null;
        }

        $raw = trim((string) $origen);
        if ($raw === '') {
            return null;
        }

        if (in_array($raw, self::ORIGENES, true)) {
            return $raw;
        }

        $key = mb_strtolower($raw);
        $key = strtr($key, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
        ]);
        $key = preg_replace('/[\s\-]+/', '_', $key) ?? $key;

        $aliases = [
            'venta' => self::ORIGEN_VENTA,
            'ventas' => self::ORIGEN_VENTA,
            'descuento_a_venta' => self::ORIGEN_VENTA,
            'descuento_venta' => self::ORIGEN_VENTA,
            'efectivo' => self::ORIGEN_VENTA,
            'caja' => self::ORIGEN_VENTA,
            'deposito' => self::ORIGEN_DEPOSITO,
            'depositos' => self::ORIGEN_DEPOSITO,
            'descuento_a_depositos' => self::ORIGEN_DEPOSITO,
            'descuento_depositos' => self::ORIGEN_DEPOSITO,
            'cuenta_deposito' => self::ORIGEN_DEPOSITO,
        ];

        return $aliases[$key] ?? null;
    }

    public static function labelForOrigen(?string $origen): string
    {
        $resolved = self::resolvedOrigen($origen);

        return self::ORIGEN_LABELS[$resolved] ?? $resolved;
    }

    /** NULL u omitido se trata como descuento a venta. */
    public static function resolvedOrigen(mixed $origen): string
    {
        return self::normalizeOrigen($origen) ?? self::ORIGEN_VENTA;
    }

    public function esPagoConDeposito(): bool
    {
        return self::resolvedOrigen($this->origen) === self::ORIGEN_DEPOSITO;
    }

    public function turnoCaja(): BelongsTo
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_caja_id');
    }

    public function cajero(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'id_user');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
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
