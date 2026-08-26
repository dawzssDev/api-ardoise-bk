<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaeCuentaContaSuc extends Model
{
    protected $table = 'mae_cuenta_conta_suc';

    public const TIPO_MAESTRA = 'maestra';

    public const TIPO_SUBCUENTA = 'subcuenta';

    public const TIPOS = [
        self::TIPO_MAESTRA,
        self::TIPO_SUBCUENTA,
    ];

    public const STATUS_INACTIVO = 0;

    public const STATUS_ACTIVO = 1;

    public const DELETED_NO = 0;

    public const DELETED_YES = 1;

    protected $fillable = [
        'negocio_id',
        'tipo_cuenta',
        'sucursal_id',
        'titulo_cuenta',
        'descripcion_cuenta',
        'created_by',
        'updated_by',
        'status',
        'deleted',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'deleted' => 'integer',
        ];
    }

    public static function normalizeTipoCuenta(mixed $tipo): ?string
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

        $aliases = [
            'maestra' => self::TIPO_MAESTRA,
            'master' => self::TIPO_MAESTRA,
            'cuenta_maestra' => self::TIPO_MAESTRA,
            'cuentamaestra' => self::TIPO_MAESTRA,
            'subcuenta' => self::TIPO_SUBCUENTA,
            'sub_cuenta' => self::TIPO_SUBCUENTA,
            'sucursal' => self::TIPO_SUBCUENTA,
        ];

        return $aliases[$key] ?? null;
    }

    public function isMaestra(): bool
    {
        return $this->tipo_cuenta === self::TIPO_MAESTRA;
    }

    public function isDeleted(): bool
    {
        return (int) $this->deleted === self::DELETED_YES;
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
        return $this->hasMany(MaeCuentaContaSucDetalle::class, 'mae_cuenta_conta_suc_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
