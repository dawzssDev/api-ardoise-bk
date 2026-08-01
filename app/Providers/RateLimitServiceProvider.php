<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap rate limiters del proyecto.
     */
    public function boot(): void
    {
        // Llave email+IP: evita credential stuffing y que un atacante bloquee
        // a un usuario legítimo conociendo solo su correo.
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)
                ->by(strtolower((string) $request->input('email')).'|'.$request->ip())
                ->response($this->tooManyAttemptsResponse());
        });

        // Frena creación masiva de cuentas.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response($this->tooManyAttemptsResponse());
        });

        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)
                ->by($request->ip())
                ->response($this->tooManyAttemptsResponse());
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->user()?->id ?: $request->ip())
                ->response($this->tooManyAttemptsResponse());
        });

        // Webhooks Stripe: límite alto para no bloquear reintentos legítimos.
        RateLimiter::for('webhooks', function (Request $request) {
            return Limit::perMinute(120)
                ->by($request->ip())
                ->response($this->tooManyAttemptsResponse());
        });
    }

    /**
     * Respuesta JSON estándar cuando se excede el límite (incluye Retry-After).
     *
     * @return callable(Request, array<string, string>): Response
     */
    private function tooManyAttemptsResponse(): callable
    {
        return function (Request $request, array $headers): Response {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests, try again later.',
                'data' => null,
                'errors' => null,
            ], 429, $headers);
        };
    }
}
