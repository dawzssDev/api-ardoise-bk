<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureMasterUser
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'Esta acción solo está disponible para el usuario maestro.',
                'data' => null,
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
