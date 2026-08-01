<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Registrar un nuevo usuario y emitir token Bearer.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Usuario registrado correctamente.',
            'data' => [
                'user' => (new UserResource($user))->resolve(),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Iniciar sesión y emitir token Bearer.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales inválidas',
                'data' => null,
                'errors' => null,
            ], 401);
        }

        // Evita acumulación de tokens con el mismo nombre
        $user->tokens()->where('name', 'api')->delete();

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Inicio de sesión correcto.',
            'data' => [
                'user' => (new UserResource($user))->resolve(),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
            'errors' => null,
        ]);
    }

    /**
     * Cerrar sesión revocando solo el token actual.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * Devolver el usuario autenticado.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'user' => (new UserResource($request->user()))->resolve(),
            ],
            'errors' => null,
        ]);
    }
}
