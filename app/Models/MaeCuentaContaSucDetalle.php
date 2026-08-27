<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaeCuentaContaSucDetalle extends Model
{
    protected $table = 'mae_cuenta_conta_suc_detalle';

    public const TIPO_DEPOSITO = 'deposito';

    public const TIPO_TRANSFERENCIA = 'transferencia';

    public const TIPOS_MOVIMIENTO = [
        self::TIPO_DEPOSITO,
        self::TIPO_TRANSFERENCIA,
    ];

    public const STATUS_INACTIVO = 0;

    public const STATUS_ACEPTADO = 1;

    public const STATUS_ACTIVO = 1;

    public const STATUS_PENDIENTE = 2;

    public const STATUS_RECHAZADO = 3;

    /**
     * @var array<int, string>
     */
    public const STATUS_LABELS = [
        self::STATUS_INACTIVO => 'inactivo',
        self::STATUS_ACEPTADO => 'aceptado',
        self::STATUS_PENDIENTE => 'pendiente',
        self::STATUS_RECHAZADO => 'rechazado',
    ];

    public const DELETED_NO = 0;

    public const DELETED_YES = 1;

    protected $fillable = [
        'negocio_id',
        'mae_cuenta_conta_suc_id',
        'tipo_movimiento',
        'cuenta_origen_id',
        'monto_movimiento',
        'cuenta_destino_id',
        'descripcion_movimiento',
        'created_by',
        'updated_by',
        'status',
        'deleted',
    ];

    protected function casts(): array
    {
        return [
            'monto_movimiento' => 'decimal:2',
            'status' => 'integer',
            'deleted' => 'integer',
        ];
    }

    public static function normalizeTipoMovimiento(mixed $tipo): ?string
    {
        if (! is_string($tipo) && ! is_numeric($tipo)) {
            return null;
        }

        $raw = trim((string) $tipo);
        if ($raw === '') {
            return null;
        }

        if (in_array($raw, self::TIPOS_MOVIMIENTO, true)) {
            return $raw;
        }

        $key = mb_strtolower($raw);
        $key = strtr($key, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u',
        ]);
        $key = preg_replace('/[\s\-]+/', '_', $key) ?? $key;

        $aliases = [
            'deposito' => self::TIPO_DEPOSITO,
            'deposit' => self::TIPO_DEPOSITO,
            'transferencia' => self::TIPO_TRANSFERENCIA,
            'transfer' => self::TIPO_TRANSFERENCIA,
        ];

        return $aliases[$key] ?? null;
    }

    public function isDeleted(): bool
    {
        return (int) $this->deleted === self::DELETED_YES;
    }

    public function isPendiente(): bool
    {
        return (int) $this->status === self::STATUS_PENDIENTE;
    }

    public static function labelForStatus(int $status): string
    {
        return self::STATUS_LABELS[$status] ?? (string) $status;
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(MaeCuentaContaSuc::class, 'mae_cuenta_conta_suc_id');
    }

    public function cuentaOrigen(): BelongsTo
    {
        return $this->belongsTo(MaeCuentaContaSuc::class, 'cuenta_origen_id');
    }

    public function cuentaDestino(): BelongsTo
    {
        return $this->belongsTo(MaeCuentaContaSuc::class, 'cuenta_destino_id');
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
