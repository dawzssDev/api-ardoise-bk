<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Proveedor\CreateProveedorRequest;
use App\Http\Requests\Proveedor\UpdateProveedorRequest;
use App\Http\Resources\ProveedorResource;
use App\Services\ProveedorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProveedorController extends Controller
{
    public function __construct(
        private readonly ProveedorService $proveedores,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->proveedores->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $status = $request->filled('status') ? (int) $request->integer('status') : null;
        $paginator = $this->proveedores->listForNegocio(
            $negocio,
            perPage: (int) $request->integer('per_page', 15),
            status: $status,
        );

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'proveedores' => ProveedorResource::collection($paginator->items())->resolve(),
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

    public function store(CreateProveedorRequest $request): JsonResponse
    {
        try {
            $negocio = $this->proveedores->negocioForUser($request->user());
            $proveedor = $this->proveedores->create(
                $negocio,
                $request->user(),
                $request->validated(),
            )->load(['createdBy:id,name,email', 'updatedBy:id,name,email']);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Proveedor creado correctamente.',
            'data' => [
                'proveedor' => (new ProveedorResource($proveedor))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->proveedores->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $proveedor = $this->proveedores->findForNegocio($negocio, $id);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'proveedor' => (new ProveedorResource($proveedor))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function update(UpdateProveedorRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->proveedores->negocioForUser($request->user());
            $proveedor = $this->proveedores->findForNegocio($negocio, $id);
            $proveedor = $this->proveedores->update($proveedor, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Proveedor actualizado correctamente.',
            'data' => [
                'proveedor' => (new ProveedorResource($proveedor))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->proveedores->negocioForUser($request->user());
            $proveedor = $this->proveedores->findForNegocio($negocio, $id);
            $proveedor = $this->proveedores->darDeBaja($proveedor, $request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Proveedor dado de baja correctamente.',
            'data' => [
                'proveedor' => (new ProveedorResource($proveedor))->resolve(),
            ],
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
