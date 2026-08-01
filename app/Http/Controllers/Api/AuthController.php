<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\NegocioResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\RegisterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function __construct(
        private readonly RegisterService $registerService,
    ) {}

    /**
     * Registrar usuario maestro + negocio y emitir token Bearer.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->registerService->register($request->validated());

        $user = $result['user'];
        $negocio = $result['negocio'];
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Cuenta y negocio creados correctamente.',
            'data' => [
                'user' => (new UserResource($user))->resolve(),
                'negocio' => (new NegocioResource($negocio))->resolve(),
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

        $user->load('negocio');
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
        $user = $request->user()->load('negocio');

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'user' => (new UserResource($user))->resolve(),
            ],
            'errors' => null,
        ]);
    }
}
