<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionViewer
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $allowedIds = config('subscriptions.viewer_user_ids', []);

        if (! $user instanceof User || ! in_array((int) $user->id, $allowedIds, true)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para consultar el listado de suscriptores.',
                'data' => null,
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
