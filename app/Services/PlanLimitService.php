<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PlanLimitService
{
    public const RESOURCE_SUCURSALES = 'sucursales';

    public const RESOURCE_INSUMOS = 'insumos';

    public const RESOURCE_STOCK_INSUMOS = 'stock_insumos';

    public const RESOURCE_PROVEEDORES = 'proveedores';

    public const RESOURCE_PRODUCTOS = 'productos';

    public const RESOURCE_STOCK_PRODUCTOS = 'stock_productos';

    public const RESOURCE_PERSONAL = 'personal';

    public const RESOURCE_CUENTAS_CONTABLES = 'cuentas_contables';

    public const RESOURCE_ROLES = 'roles';

    public const RESOURCE_STAFF = 'staff';

    /**
     * @return list<string>
     */
    public static function limitColumns(): array
    {
        return [
            'limit_sucursales',
            'limit_insumos',
            'limit_stock_insumos',
            'limit_proveedores',
            'limit_productos',
            'limit_stock_productos',
            'limit_personal',
            'limit_cuentas_contables',
            'limit_roles',
            'limit_staff',
        ];
    }

    public function tierForPlan(string $plan): string
    {
        $key = strtolower(trim($plan));
        $map = config('plans.plan_tier', []);

        return $map[$key] ?? 'basico';
    }

    /**
     * @return array<string, int|string>
     */
    public function limitsForTier(string $tier): array
    {
        $tiers = config('plans.tiers', []);
        $limits = $tiers[$tier] ?? $tiers['basico'] ?? [];

        $out = [];
        foreach (self::limitColumns() as $column) {
            $out[$column] = (int) ($limits[$column] ?? 0);
        }

        return $out;
    }

    public function applyTierToUser(User $user, string $tier): User
    {
        if ((int) $user->user_ardo_vip === 1) {
            return $user;
        }

        $user->forceFill($this->limitsForTier($tier))->save();

        return $user->refresh();
    }

    public function applyFromPlan(User $user, string $plan): User
    {
        return $this->applyTierToUser($user, $this->tierForPlan($plan));
    }

    public function applyFromPriceId(User $user, ?string $priceId, ?StripeService $stripe = null): User
    {
        if ((int) $user->user_ardo_vip === 1) {
            return $user;
        }

        if ($priceId === null || $priceId === '') {
            return $this->applyTierToUser($user, 'basico');
        }

        $plan = $this->planKeyForPriceId($priceId);

        if ($plan === null && $stripe !== null) {
            try {
                $plan = $stripe->resolvePlanByPriceId($priceId);
            } catch (\Throwable) {
                $plan = null;
            }
        }

        return $this->applyFromPlan($user, $plan ?? 'basico_mensual');
    }

    /**
     * Resuelve plan desde price_id usando config (sin llamar a StripeService).
     */
    public function planKeyForPriceId(string $priceId): ?string
    {
        $map = [
            'basico_mensual' => config('services.stripe.price_basico_mensual'),
            'basico_anual' => config('services.stripe.price_basico_anual'),
            'plus_mensual' => config('services.stripe.price_plus_mensual'),
            'plus_anual' => config('services.stripe.price_plus_anual'),
            'prueba' => config('services.stripe.price_prueba'),
            'mensual' => config('services.stripe.price_mensual'),
            'anual' => config('services.stripe.price_anual'),
        ];

        foreach ($map as $plan => $configured) {
            if (is_string($configured) && $configured !== '' && $configured === $priceId) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Bloquea la creación si el negocio alcanzó el límite del plan del dueño.
     *
     * @param  self::RESOURCE_*  $resource
     */
    public function assertCanCreate(Negocio $negocio, string $resource, ?int $sucursalId = null): void
    {
        $owner = $this->ownerOrNull($negocio);

        if (! $owner || (int) $owner->user_ardo_vip === 1) {
            return;
        }

        $column = $this->columnForResource($resource);
        $limit = $this->resolvedLimit($owner, $column);

        if ($limit <= 0) {
            throw new HttpException(422, $this->limitMessage($resource, $limit));
        }

        $current = $this->countForResource($negocio, $resource, $sucursalId);

        if ($current >= $limit) {
            throw new HttpException(422, $this->limitMessage($resource, $limit));
        }
    }

    /**
     * Si ya se alcanzó el tope de productos/insumos, no permite más categorías.
     *
     * @param  self::RESOURCE_PRODUCTOS|self::RESOURCE_INSUMOS  $catalogResource
     */
    public function assertCanCreateCategoria(Negocio $negocio, string $catalogResource): void
    {
        $owner = $this->ownerOrNull($negocio);

        if (! $owner || (int) $owner->user_ardo_vip === 1) {
            return;
        }

        $column = $this->columnForResource($catalogResource);
        $limit = $this->resolvedLimit($owner, $column);

        if ($limit <= 0) {
            throw new HttpException(422, $this->categoriaBlockedMessage($catalogResource, $limit));
        }

        $current = $this->countForResource($negocio, $catalogResource, null);

        if ($current >= $limit) {
            throw new HttpException(422, $this->categoriaBlockedMessage($catalogResource, $limit));
        }
    }

    private function ownerOrNull(Negocio $negocio): ?User
    {
        return $negocio->masterUser()->first() ?? User::query()->find($negocio->user_id);
    }

    private function resolvedLimit(User $owner, string $column): int
    {
        $limit = $owner->{$column};

        if ($limit === null) {
            $limit = $this->limitsForTier('basico')[$column] ?? 0;
        }

        return (int) $limit;
    }

    private function categoriaBlockedMessage(string $catalogResource, int $limit): string
    {
        if ($catalogResource === self::RESOURCE_INSUMOS) {
            return "Alcanzaste el límite de insumos de tu plan ({$limit}). No puedes crear más categorías de insumos.";
        }

        return "Alcanzaste el límite de productos de tu plan ({$limit}). No puedes crear más categorías de productos.";
    }

    private function columnForResource(string $resource): string
    {
        return match ($resource) {
            self::RESOURCE_SUCURSALES => 'limit_sucursales',
            self::RESOURCE_INSUMOS => 'limit_insumos',
            self::RESOURCE_STOCK_INSUMOS => 'limit_stock_insumos',
            self::RESOURCE_PROVEEDORES => 'limit_proveedores',
            self::RESOURCE_PRODUCTOS => 'limit_productos',
            self::RESOURCE_STOCK_PRODUCTOS => 'limit_stock_productos',
            self::RESOURCE_PERSONAL => 'limit_personal',
            self::RESOURCE_CUENTAS_CONTABLES => 'limit_cuentas_contables',
            self::RESOURCE_ROLES => 'limit_roles',
            self::RESOURCE_STAFF => 'limit_staff',
            default => throw new HttpException(500, "Recurso de límite desconocido: {$resource}"),
        };
    }

    private function countForResource(Negocio $negocio, string $resource, ?int $sucursalId): int
    {
        return match ($resource) {
            self::RESOURCE_SUCURSALES => $negocio->sucursales()->count(),
            self::RESOURCE_INSUMOS => $negocio->insumos()->count(),
            self::RESOURCE_STOCK_INSUMOS => $negocio->stockInsumos()
                ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
                ->count(),
            self::RESOURCE_PROVEEDORES => $negocio->proveedores()->count(),
            self::RESOURCE_PRODUCTOS => $negocio->productos()->count(),
            self::RESOURCE_STOCK_PRODUCTOS => $negocio->stockProductos()
                ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
                ->count(),
            self::RESOURCE_PERSONAL => $negocio->empleados()->count(),
            self::RESOURCE_CUENTAS_CONTABLES => $negocio->cuentasContables()
                ->where('deleted', 0)
                ->count(),
            self::RESOURCE_ROLES => $negocio->roles()->count(),
            self::RESOURCE_STAFF => $negocio->staff()->count(),
            default => 0,
        };
    }

    private function limitMessage(string $resource, int $limit): string
    {
        $labels = [
            self::RESOURCE_SUCURSALES => 'sucursales',
            self::RESOURCE_INSUMOS => 'insumos',
            self::RESOURCE_STOCK_INSUMOS => 'registros de stock de insumos por sucursal',
            self::RESOURCE_PROVEEDORES => 'proveedores',
            self::RESOURCE_PRODUCTOS => 'productos',
            self::RESOURCE_STOCK_PRODUCTOS => 'registros de stock de productos por sucursal',
            self::RESOURCE_PERSONAL => 'empleados (personal)',
            self::RESOURCE_CUENTAS_CONTABLES => 'cuentas contables',
            self::RESOURCE_ROLES => 'roles',
            self::RESOURCE_STAFF => 'usuarios staff',
        ];

        $label = $labels[$resource] ?? $resource;

        return "Alcanzaste el límite de tu plan ({$limit} {$label}). Mejora a Plus o contacta soporte VIP.";
    }
}
