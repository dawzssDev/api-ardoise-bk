<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sucursal\CreateSucursalRequest;
use App\Http\Requests\Sucursal\UpdateSucursalRequest;
use App\Http\Resources\SucursalResource;
use App\Services\SucursalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SucursalController extends Controller
{
    public function __construct(
        private readonly SucursalService $sucursales,
    ) {}

    /**
     * Listar sucursales/bodegas del negocio del usuario.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->sucursales->negocioForUser($request->user());
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e->getStatusCode());
        }

        $paginator = $this->sucursales->listForNegocio($negocio);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'sucursales' => SucursalResource::collection($paginator->items())->resolve(),
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
     * Registrar una sucursal o bodega ligada al negocio del usuario.
     */
    public function store(CreateSucursalRequest $request): JsonResponse
    {
        try {
            $negocio = $this->sucursales->negocioForUser($request->user());
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e->getStatusCode());
        }

        $sucursal = $this->sucursales->create($negocio, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Sucursal creada correctamente.',
            'data' => [
                'sucursal' => (new SucursalResource($sucursal))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Detalle de una sucursal/bodega del negocio del usuario.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->sucursales->negocioForUser($request->user());
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e->getStatusCode());
        }

        $sucursal = $this->sucursales->findForNegocio($negocio, $id);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'sucursal' => (new SucursalResource($sucursal))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Actualizar una sucursal/bodega del negocio del usuario.
     */
    public function update(UpdateSucursalRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->sucursales->negocioForUser($request->user());
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e->getStatusCode());
        }

        $sucursal = $this->sucursales->findForNegocio($negocio, $id);
        $sucursal = $this->sucursales->update($sucursal, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Sucursal actualizada correctamente.',
            'data' => [
                'sucursal' => (new SucursalResource($sucursal))->resolve(),
            ],
            'errors' => null,
        ]);
    }
}
