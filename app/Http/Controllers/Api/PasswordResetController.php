<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * Solicitar enlace de recuperación (respuesta genérica siempre).
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'success' => true,
            'message' => 'Si el correo existe, se envió un enlace de recuperación',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * Restablecer contraseña y revocar todos los tokens del usuario.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
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
                'message' => __($status),
                'data' => null,
                'errors' => null,
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Contraseña restablecida correctamente.',
            'data' => null,
            'errors' => null,
        ]);
    }
}
