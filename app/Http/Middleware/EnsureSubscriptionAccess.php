<?php

namespace App\Http\Middleware;

use App\Models\Staff;
use App\Models\User;
use App\Services\SubscriptionAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionAccess
{
    public function __construct(
        private readonly SubscriptionAccessService $access,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        if (! $actor instanceof User && ! $actor instanceof Staff) {
            return $next($request);
        }

        $owner = $this->access->ownerForActor($actor);
        if (! $owner instanceof User) {
            return $next($request);
        }

        $this->access->applyForUser($owner);

        if ($actor instanceof Staff && $owner->isPosBlocked()) {
            if ($request->is('api/auth/logout')) {
                return $next($request);
            }

            return $this->denied(
                'El acceso al sistema está bloqueado. El titular del negocio debe renovar la suscripción.',
                $owner,
            );
        }

        if ($actor instanceof User && $owner->isPosBlocked() && ! $this->isAllowedWhenBlocked($request)) {
            $snapshot = $this->access->snapshot($owner);

            return $this->denied(
                $snapshot['message'] ?? 'Tu suscripción está bloqueada. Entra a Mi Negocio para reactivar tu cuenta.',
                $owner,
                $snapshot,
            );
        }

        return $next($request);
    }

    private function isAllowedWhenBlocked(Request $request): bool
    {
        return $request->is(
            'api/auth/logout',
            'api/auth/me',
            'api/user',
            'api/negocio',
            'api/subscriptions',
            'api/subscriptions/*',
            'api/payments',
            'api/payments/*',
            'api/invoices',
            'api/invoices/*',
        );
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    private function denied(string $message, User $owner, ?array $snapshot = null): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => [
                'block_POS' => (int) $owner->block_POS,
                'subscription_access' => $snapshot,
            ],
            'errors' => null,
        ], 403);
    }
}
