<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Producto extends Model
{
    protected $table = 'productos';

    public const STATUS_INACTIVO = 0;

    public const STATUS_ACTIVO = 1;

    protected $fillable = [
        'negocio_id',
        'categoria_producto_id',
        'name',
        'price',
        'image',
        'descuento_stock_prod',
        'descuento_stock_insum',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'descuento_stock_prod' => 'array',
            'descuento_stock_insum' => 'array',
            'status' => 'integer',
        ];
    }

    /**
     * Normaliza el JSON de productos a descontar: [{id, Producto, cantidad}].
     *
     * @return list<array{id: int|null, Producto: string, cantidad: float|int|string|null}>|mixed
     */
    public static function normalizeDescuentoStockProd(mixed $value): mixed
    {
        return self::normalizeDescuentoList($value, 'Producto', [
            'id', 'producto_id', 'productoId',
        ], [
            'Producto', 'producto', 'name', 'nombre',
        ]);
    }

    /**
     * Normaliza el JSON de insumos a descontar: [{id, Insumo, cantidad}].
     *
     * @return list<array{id: int|null, Insumo: string, cantidad: float|int|string|null}>|mixed
     */
    public static function normalizeDescuentoStockInsum(mixed $value): mixed
    {
        return self::normalizeDescuentoList($value, 'Insumo', [
            'id', 'insumo_id', 'insumoId',
        ], [
            'Insumo', 'insumo', 'name', 'nombre',
        ]);
    }

    /**
     * @param  list<string>  $idKeys
     * @param  list<string>  $nameKeys
     * @return list<array<string, mixed>>|mixed
     */
    private static function normalizeDescuentoList(
        mixed $value,
        string $entity,
        array $idKeys,
        array $nameKeys,
    ): mixed {
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

        $items = array_is_list($value) ? $value : array_values($value);
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = null;
            foreach ($idKeys as $key) {
                if (array_key_exists($key, $item) && $item[$key] !== null && $item[$key] !== '') {
                    $id = (int) $item[$key];
                    break;
                }
            }

            $name = '';
            foreach ($nameKeys as $key) {
                if (array_key_exists($key, $item) && $item[$key] !== null && $item[$key] !== '') {
                    $name = trim((string) $item[$key]);
                    break;
                }
            }

            $normalized[] = [
                'id' => $id,
                $entity => $name,
                'cantidad' => $item['cantidad']
                    ?? $item['Cantidad']
                    ?? $item['quantity']
                    ?? $item['qty']
                    ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * Resuelve nombres/ids de productos a descontar contra el catálogo del negocio.
     * Puede incluir el mismo producto (se descuenta a sí mismo al vender).
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: list<array{id: int, Producto: string, cantidad: float}>, 1: array<string, string>}
     */
    public static function resolveDescuentoStockProd(int $negocioId, array $items): array
    {
        $resolved = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $id = $item['id'] ?? $item['producto_id'] ?? null;
            $name = trim((string) ($item['Producto'] ?? $item['producto'] ?? ''));

            $query = self::query()->where('negocio_id', $negocioId);
            $producto = null;

            if ($id) {
                $producto = (clone $query)->whereKey($id)->first();
                if (! $producto) {
                    $errors["descuento_stock_prod.{$index}.id"] = "El producto #{$id} no existe en tu negocio.";
                    continue;
                }
            } elseif ($name !== '') {
                $producto = (clone $query)->where('name', $name)->first();
                if (! $producto) {
                    $errors["descuento_stock_prod.{$index}.Producto"] = "El producto \"{$name}\" no existe en tu negocio.";
                    continue;
                }
            } else {
                $errors["descuento_stock_prod.{$index}.Producto"] = 'Debes indicar el producto (id o nombre).';
                continue;
            }

            $key = (int) $producto->id;
            $cantidad = (float) $item['cantidad'];

            if (isset($resolved[$key])) {
                $resolved[$key]['cantidad'] = round($resolved[$key]['cantidad'] + $cantidad, 3);
            } else {
                $resolved[$key] = [
                    'id' => $key,
                    'Producto' => $producto->name,
                    'cantidad' => round($cantidad, 3),
                ];
            }
        }

        return [array_values($resolved), $errors];
    }

    /**
     * Resuelve nombres/ids de insumos a descontar contra el catálogo del negocio.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array{0: list<array{id: int, Insumo: string, cantidad: float}>, 1: array<string, string>}
     */
    public static function resolveDescuentoStockInsum(int $negocioId, array $items): array
    {
        $resolved = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $id = $item['id'] ?? $item['insumo_id'] ?? null;
            $name = trim((string) ($item['Insumo'] ?? $item['insumo'] ?? ''));

            $query = Insumo::query()->where('negocio_id', $negocioId);
            $insumo = null;

            if ($id) {
                $insumo = (clone $query)->whereKey($id)->first();
                if (! $insumo) {
                    $errors["descuento_stock_insum.{$index}.id"] = "El insumo #{$id} no existe en tu negocio.";
                    continue;
                }
            } elseif ($name !== '') {
                $insumo = (clone $query)->where('name', $name)->first();
                if (! $insumo) {
                    $errors["descuento_stock_insum.{$index}.Insumo"] = "El insumo \"{$name}\" no existe en tu negocio.";
                    continue;
                }
            } else {
                $errors["descuento_stock_insum.{$index}.Insumo"] = 'Debes indicar el insumo (id o nombre).';
                continue;
            }

            $key = (int) $insumo->id;
            $cantidad = (float) $item['cantidad'];

            if (isset($resolved[$key])) {
                $resolved[$key]['cantidad'] = round($resolved[$key]['cantidad'] + $cantidad, 3);
            } else {
                $resolved[$key] = [
                    'id' => $key,
                    'Insumo' => $insumo->name,
                    'cantidad' => round($cantidad, 3),
                ];
            }
        }

        return [array_values($resolved), $errors];
    }

    public function isActivo(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVO;
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaProducto::class, 'categoria_producto_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(StockProducto::class);
    }

    public function ordenDetalles(): HasMany
    {
        return $this->hasMany(OrdenDetalle::class, 'producto_id');
    }

    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        $path = $this->image;

        // Compatibilidad con rutas viejas: productos/1/archivo.png
        if (str_starts_with($path, 'productos/')) {
            $path = substr($path, strlen('productos/'));
        }

        $parts = explode('/', $path, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return route('productos.image', [
            'negocioId' => $parts[0],
            'filename' => $parts[1],
        ]);
    }
}
