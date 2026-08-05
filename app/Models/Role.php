<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    protected $table = 'roles';

    public const PERMISSION_KEYS = [
        'pos',
        'kitchen',
        'branch_inventory',
        'central_warehouse',
        'branches',
        'insumos',
        'stock_insumos',
        'products',
        'finance',
        'staff',
        'supply_requests',
        'business',
        'users',
        'roles_permissions',
        'nuevoPedido',
        'enPreparacionPedido',
        'pedidosListos',
    ];

    /**
     * Alias de front / payloads antiguos → clave canónica.
     *
     * @var array<string, string>
     */
    public const PERMISSION_ALIASES = [
        'nuevo' => 'nuevoPedido',
        'nuevo_pedido' => 'nuevoPedido',
        'new' => 'nuevoPedido',
        'enPreparacion' => 'enPreparacionPedido',
        'en_preparacion' => 'enPreparacionPedido',
        'en_preparacion_pedido' => 'enPreparacionPedido',
        'listo' => 'pedidosListos',
        'listos' => 'pedidosListos',
        'pedidos_listos' => 'pedidosListos',
        'ready' => 'pedidosListos',
    ];

    protected $fillable = [
        'negocio_id',
        'name',
        'permissions',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'status' => 'boolean',
        ];
    }

    /**
     * Mapa completo de permisos (clave => bool), faltantes en false.
     *
     * @param  array<string, mixed>|null  $permissions
     * @return array<string, bool>
     */
    public static function normalizePermissions(?array $permissions): array
    {
        $permissions = self::canonicalizePermissionKeys($permissions ?? []);
        $normalized = [];

        foreach (self::PERMISSION_KEYS as $key) {
            $normalized[$key] = self::toBool($permissions[$key] ?? false);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $permissions
     * @return array<string, mixed>
     */
    public static function canonicalizePermissionKeys(array $permissions): array
    {
        foreach (self::PERMISSION_ALIASES as $alias => $canonical) {
            if (! array_key_exists($alias, $permissions)) {
                continue;
            }

            if (! array_key_exists($canonical, $permissions)) {
                $permissions[$canonical] = $permissions[$alias];
                continue;
            }

            // Si el alias viene en true y la canónica en false, priorizar el alias.
            if (self::toBool($permissions[$alias]) && ! self::toBool($permissions[$canonical])) {
                $permissions[$canonical] = $permissions[$alias];
            }
        }

        return $permissions;
    }

    /**
     * Mapa por defecto: todos en false.
     *
     * @return array<string, bool>
     */
    public static function defaultPermissions(): array
    {
        return self::normalizePermissions([]);
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'on', 'yes', 'si', 'sí' => true,
            default => false,
        };
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function empleados(): HasMany
    {
        return $this->hasMany(Empleado::class);
    }
}
