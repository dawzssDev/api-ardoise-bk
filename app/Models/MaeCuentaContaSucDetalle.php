<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaeCuentaContaSucDetalle extends Model
{
    protected $table = 'mae_cuenta_conta_suc_detalle';

    public const TIPO_DEPOSITO = 'deposito';

    public const TIPO_TRANSFERENCIA = 'transferencia';

    /** Solicitud de retiro sucursal → matriz (pendiente de autorización en sucursal). */
    public const TIPO_RETIRO = 'retiro';

    /** Venta en efectivo del corte gerencial → abona cuenta matriz. */
    public const TIPO_VENTA_EFECTIVO = 'venta_efectivo';

    /** Venta con tarjeta del corte gerencial → abona cuenta matriz. */
    public const TIPO_VENTA_TARJETA = 'venta_tarjeta';

    /** Comisión de terminal del corte gerencial → descuenta cuenta matriz. */
    public const TIPO_COMISION_TERMINAL = 'comision_terminal';

    /** Gasto directo sobre una cuenta (no es traspaso). */
    public const TIPO_GASTO = 'gasto';

    public const TIPO_GASTO_OPERATIVO = 'gasto_operativo';

    public const TIPO_PAGO_PROVEEDOR = 'pago_proveedor';

    public const TIPO_RETIRO_EFECTIVO = 'retiro_efectivo';

    public const TIPOS_GASTO = [
        self::TIPO_GASTO,
        self::TIPO_GASTO_OPERATIVO,
        self::TIPO_PAGO_PROVEEDOR,
        self::TIPO_RETIRO_EFECTIVO,
        self::TIPO_COMISION_TERMINAL,
    ];

    public const TIPOS_MOVIMIENTO = [
        self::TIPO_DEPOSITO,
        self::TIPO_TRANSFERENCIA,
        self::TIPO_RETIRO,
        self::TIPO_VENTA_EFECTIVO,
        self::TIPO_VENTA_TARJETA,
        self::TIPO_COMISION_TERMINAL,
        self::TIPO_GASTO,
        self::TIPO_GASTO_OPERATIVO,
        self::TIPO_PAGO_PROVEEDOR,
        self::TIPO_RETIRO_EFECTIVO,
    ];

    public const TIPOS_VENTA_CORTE = [
        self::TIPO_VENTA_EFECTIVO,
        self::TIPO_VENTA_TARJETA,
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
            'retiro' => self::TIPO_RETIRO,
            'withdrawal' => self::TIPO_RETIRO,
            'venta_efectivo' => self::TIPO_VENTA_EFECTIVO,
            'venta_en_efectivo' => self::TIPO_VENTA_EFECTIVO,
            'ventaefectivo' => self::TIPO_VENTA_EFECTIVO,
            'venta_tarjeta' => self::TIPO_VENTA_TARJETA,
            'venta_con_tarjeta' => self::TIPO_VENTA_TARJETA,
            'ventatarjeta' => self::TIPO_VENTA_TARJETA,
            'comision_terminal' => self::TIPO_COMISION_TERMINAL,
            'comision_de_terminal' => self::TIPO_COMISION_TERMINAL,
            'comisionterminal' => self::TIPO_COMISION_TERMINAL,
            'gasto' => self::TIPO_GASTO,
            'gasto_operativo' => self::TIPO_GASTO_OPERATIVO,
            'gastooperativo' => self::TIPO_GASTO_OPERATIVO,
            'pago_proveedor' => self::TIPO_PAGO_PROVEEDOR,
            'pagoproveedor' => self::TIPO_PAGO_PROVEEDOR,
            'pago_a_proveedor' => self::TIPO_PAGO_PROVEEDOR,
            'retiro_efectivo' => self::TIPO_RETIRO_EFECTIVO,
            'retiroefectivo' => self::TIPO_RETIRO_EFECTIVO,
            'retiro_de_efectivo' => self::TIPO_RETIRO_EFECTIVO,
        ];

        return $aliases[$key] ?? null;
    }

    public function isGasto(): bool
    {
        return self::isTipoGasto((string) $this->tipo_movimiento);
    }

    public static function isTipoGasto(?string $tipo): bool
    {
        return $tipo !== null && in_array($tipo, self::TIPOS_GASTO, true);
    }

    public function isVentaCorte(): bool
    {
        return in_array($this->tipo_movimiento, self::TIPOS_VENTA_CORTE, true);
    }

    public function isDeleted(): bool
    {
        return (int) $this->deleted === self::DELETED_YES;
    }

    public function isPendiente(): bool
    {
        return (int) $this->status === self::STATUS_PENDIENTE;
    }

    public function isRetiro(): bool
    {
        return $this->tipo_movimiento === self::TIPO_RETIRO;
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
