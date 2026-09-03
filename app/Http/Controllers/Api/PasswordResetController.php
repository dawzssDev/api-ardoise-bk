<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * Solicitar enlace de recuperación (solo tabla users).
     * Respuesta genérica siempre (no revela si el correo existe).
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            Password::broker('users')->sendResetLink(
                $request->only('email'),
            );
        } catch (\Throwable $e) {
            Log::error('No se pudo enviar correo de recuperación de contraseña', [
                'email' => $request->validated('email'),
                'message' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Si el correo existe en el sistema, enviamos un enlace para restablecer tu contraseña.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * Restablecer contraseña con token del correo.
     * Al guardar, Laravel elimina el token (el enlace deja de servir).
     * También se revocan todos los tokens Sanctum del usuario.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password): void {
                // El cast 'hashed' del modelo se encarga del hash
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                // Revoca todos los tokens Sanctum del usuario
                $user->tokens()->delete();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'success' => false,
                'message' => $this->resetErrorMessage((string) $status),
                'data' => null,
                'errors' => null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Contraseña restablecida correctamente. Ya puedes iniciar sesión.',
            'data' => null,
            'errors' => null,
        ]);
    }

    private function resetErrorMessage(string $status): string
    {
        return match ($status) {
            Password::INVALID_TOKEN => 'El enlace de recuperación no es válido o ya expiró. Solicita uno nuevo.',
            Password::INVALID_USER => 'No encontramos una cuenta con ese correo.',
            Password::RESET_THROTTLED => 'Espera un momento antes de intentarlo de nuevo.',
            default => 'No se pudo restablecer la contraseña. Solicita un nuevo enlace.',
        };
    }
}
