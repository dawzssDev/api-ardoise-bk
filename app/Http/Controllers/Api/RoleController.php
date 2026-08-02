<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\CreateRoleRequest;
use App\Http\Requests\Role\ToggleRoleStatusRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
    ) {}

    /**
     * Listar roles del negocio del usuario.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->roles->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $paginator = $this->roles->listForNegocio($negocio);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'roles' => RoleResource::collection($paginator->items())->resolve(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ],
            'errors' => null,
        ]);
    }

    /**
     * Crear un rol del negocio del usuario.
     */
    public function store(CreateRoleRequest $request): JsonResponse
    {
        try {
            $negocio = $this->roles->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $role = $this->roles->create(
            $negocio,
            $request->user(),
            $request->validated(),
        )->load(['createdBy:id,name,email', 'updatedBy:id,name,email']);

        return response()->json([
            'success' => true,
            'message' => 'Rol creado correctamente.',
            'data' => [
                'role' => (new RoleResource($role))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Detalle de un rol del negocio del usuario.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->roles->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $role = $this->roles->findForNegocio($negocio, $id);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'role' => (new RoleResource($role))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Actualizar un rol del negocio del usuario.
     */
    public function update(UpdateRoleRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->roles->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $role = $this->roles->findForNegocio($negocio, $id);
        $role = $this->roles->update($role, $request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Rol actualizado correctamente.',
            'data' => [
                'role' => (new RoleResource($role))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Activar o desactivar un rol.
     */
    public function setStatus(ToggleRoleStatusRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->roles->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $role = $this->roles->findForNegocio($negocio, $id);
        $status = (bool) $request->validated('status');
        $role = $this->roles->setStatus($role, $request->user(), $status);

        return response()->json([
            'success' => true,
            'message' => $status
                ? 'Rol activado correctamente.'
                : 'Rol desactivado correctamente.',
            'data' => [
                'role' => (new RoleResource($role))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Eliminar un rol del negocio del usuario.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->roles->negocioForUser($request->user());
            $role = $this->roles->findForNegocio($negocio, $id);
            $this->roles->delete($role);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Rol eliminado correctamente.',
            'data' => null,
            'errors' => null,
        ]);
    }

    private function errorResponse(HttpException $e): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'data' => null,
            'errors' => null,
        ], $e->getStatusCode());
    }
}
