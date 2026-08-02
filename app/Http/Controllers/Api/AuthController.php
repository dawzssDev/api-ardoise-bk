<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\NegocioResource;
use App\Http\Resources\StaffResource;
use App\Http\Resources\UserResource;
use App\Models\Staff;
use App\Models\User;
use App\Services\AuthService;
use App\Services\RegisterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthController extends Controller
{
    public function __construct(
        private readonly RegisterService $registerService,
        private readonly AuthService $authService,
    ) {}

    /**
     * Registrar usuario maestro + negocio y emitir token Bearer.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->registerService->register($request->validated());

        $user = $result['user'];
        $negocio = $result['negocio'];
        $token = $this->authService->issueToken($user);

        return response()->json([
            'success' => true,
            'message' => 'Cuenta y negocio creados correctamente.',
            'data' => [
                'type' => 'user',
                'user' => (new UserResource($user))->resolve(),
                'negocio' => (new NegocioResource($negocio))->resolve(),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Iniciar sesión (users maestro o staff) y emitir token Bearer.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->attempt(
                $request->loginIdentifier(),
                $request->validated('password'),
            );
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e->getStatusCode());
        }

        /** @var User|Staff $actor */
        $actor = $result['actor'];
        $token = $this->authService->issueToken($actor);

        if ($result['type'] === 'staff') {
            /** @var Staff $actor */
            $actor->load([
                'negocio',
                'sucursal:id,negocio_id,type,name',
                'role:id,negocio_id,name,permissions,status',
                'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname,employee_number,status',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Inicio de sesión correcto.',
                'data' => [
                    'type' => 'staff',
                    'user' => null,
                    'staff' => (new StaffResource($actor))->resolve(),
                    'negocio' => $actor->negocio
                        ? (new NegocioResource($actor->negocio))->resolve()
                        : null,
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
                'errors' => null,
            ]);
        }

        /** @var User $actor */
        $actor->load('negocio');

        return response()->json([
            'success' => true,
            'message' => 'Inicio de sesión correcto.',
            'data' => [
                'type' => 'user',
                'user' => (new UserResource($actor))->resolve(),
                'staff' => null,
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
     * Devolver el actor autenticado (maestro o staff).
     */
    public function me(Request $request): JsonResponse
    {
        $actor = $request->user();

        if ($actor instanceof Staff) {
            $actor->load([
                'negocio',
                'sucursal:id,negocio_id,type,name',
                'role:id,negocio_id,name,permissions,status',
                'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname,employee_number,status',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'ok',
                'data' => [
                    'type' => 'staff',
                    'user' => null,
                    'staff' => (new StaffResource($actor))->resolve(),
                    'negocio' => $actor->negocio
                        ? (new NegocioResource($actor->negocio))->resolve()
                        : null,
                ],
                'errors' => null,
            ]);
        }

        $actor->load('negocio');

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'type' => 'user',
                'user' => (new UserResource($actor))->resolve(),
                'staff' => null,
            ],
            'errors' => null,
        ]);
    }
}
