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

    /**
     * @var array<string, string>
     */
    public const TIPO_LABELS = [
        self::TIPO_PAGO_PROVEEDOR => 'Pago proveedor',
        self::TIPO_GASTO_OPERATIVO => 'Gasto operativo',
        self::TIPO_RETIRO_EFECTIVO => 'Retiro de efectivo',
    ];

    protected $fillable = [
        'turno_caja_id',
        'id_user',
        'user_id',
        'negocio_id',
        'sucursal_id',
        'tipo_gasto',
        'proveedor_id',
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
