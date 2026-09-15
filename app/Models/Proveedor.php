<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    protected $table = 'proveedores';

    public const STATUS_BAJA = 0;

    public const STATUS_ACTIVO = 1;

    /**
     * Campos comerciales varchar nulleables (sin InsumosPrecios).
     *
     * @var list<string>
     */
    public const CAMPOS_COMERCIALES_STRING = [
        'dp_Folio',
        'dp_Categoria',
        'cce_momento_pedido',
        'cce_dias_entrega',
        'cce_envioDom_costo',
        'cce_forma_pago',
        'cce_condiciones_pagos',
        'cce_solicitar_factura',
        'cce_pedido_min',
        'cce_descansos',
        'cce_tiempo_entrega',
        'cce_descuento_pVolumen',
        'cce_lugar_entrega',
        'cce_frecuencia_pedido',
        'cce_NoTarjetaClave',
        'cce_banco',
        'cce_propietario',
        'Incidencias',
    ];

    /**
     * @var list<string>
     */
    public const CAMPOS_COMERCIALES = [
        ...self::CAMPOS_COMERCIALES_STRING,
        'InsumosPrecios',
    ];

    protected $fillable = [
        'negocio_id',
        'name',
        'legal_name',
        'rfc',
        'phone',
        'email',
        'address',
        'contact_name',
        'notes',
        'dp_Folio',
        'dp_Categoria',
        'cce_momento_pedido',
        'cce_dias_entrega',
        'cce_envioDom_costo',
        'cce_forma_pago',
        'cce_condiciones_pagos',
        'cce_solicitar_factura',
        'cce_pedido_min',
        'cce_descansos',
        'cce_tiempo_entrega',
        'cce_descuento_pVolumen',
        'cce_lugar_entrega',
        'cce_frecuencia_pedido',
        'cce_NoTarjetaClave',
        'cce_banco',
        'cce_propietario',
        'InsumosPrecios',
        'Incidencias',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'InsumosPrecios' => 'array',
        ];
    }

    /**
     * Normaliza el JSON de precios de insumos enviado por el frontend.
     *
     * Estructura: { "No.", "InsumoProductio", "Presentacion", "Precio", "Precio pUnidad", "ProveedorSuplente" }
     * Acepta un objeto, una lista de objetos, o un string JSON.
     *
     * @return array<string, mixed>|list<array<string, mixed>>|mixed|null
     */
    public static function normalizeInsumosPrecios(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $value;
            }
            $value = $decoded;
        }

        if (! is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return [];
        }

        $isList = array_is_list($value);
        $items = $isList ? $value : [$value];
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                return $value;
            }

            $normalized[] = [
                'No.' => self::stringFromKeys($item, ['No.', 'No', 'no', 'numero', 'num']),
                'InsumoProductio' => self::stringFromKeys($item, [
                    'InsumoProductio', 'InsumoProducto', 'insumoProducto', 'insumo', 'Insumo',
                ]),
                'Presentacion' => self::stringFromKeys($item, ['Presentacion', 'presentacion']),
                'Precio' => self::stringFromKeys($item, ['Precio', 'precio']),
                'Precio pUnidad' => self::stringFromKeys($item, [
                    'Precio pUnidad', 'Precio p Unidad', 'precio_p_unidad', 'precioUnidad', 'precio_unidad',
                ]),
                'ProveedorSuplente' => self::stringFromKeys($item, [
                    'ProveedorSuplente', 'proveedor_suplente', 'proveedorSuplente',
                ]),
            ];
        }

        return $isList ? $normalized : $normalized[0];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $keys
     */
    private static function stringFromKeys(array $item, array $keys): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $item) && $item[$key] !== null) {
                return is_scalar($item[$key]) ? (string) $item[$key] : '';
            }
        }

        return '';
    }

    public function isActivo(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVO;
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function gastosEnTurno(): HasMany
    {
        return $this->hasMany(GastoEnTurno::class);
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
