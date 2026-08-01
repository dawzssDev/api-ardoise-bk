<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
    ) {}

    /**
     * Ver perfil del usuario autenticado (incluye negocio).
     */
    public function show(Request $request): JsonResponse
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

    /**
     * Actualizar datos del usuario autenticado.
     */
    public function update(UpdateUserRequest $request): JsonResponse
    {
        try {
            $user = $this->users->update(
                $request->user(),
                $request->validated(),
            );
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?? 'No se pudo actualizar el usuario.',
                'data' => null,
                'errors' => $e->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Usuario actualizado correctamente.',
            'data' => [
                'user' => (new UserResource($user))->resolve(),
            ],
            'errors' => null,
        ]);
    }
}
