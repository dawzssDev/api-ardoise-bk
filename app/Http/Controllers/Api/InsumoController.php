<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Insumo\CreateInsumoRequest;
use App\Http\Requests\Insumo\ToggleInsumoStatusRequest;
use App\Http\Requests\Insumo\UpdateInsumoRequest;
use App\Http\Resources\InsumoResource;
use App\Services\InsumoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InsumoController extends Controller
{
    public function __construct(
        private readonly InsumoService $insumos,
    ) {}

    /**
     * Listar insumos del negocio del usuario.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->insumos->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $paginator = $this->insumos->listForNegocio($negocio);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'insumos' => InsumoResource::collection($paginator->items())->resolve(),
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
     * Registrar un insumo del negocio del usuario.
     */
    public function store(CreateInsumoRequest $request): JsonResponse
    {
        try {
            $negocio = $this->insumos->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $insumo = $this->insumos->create(
            $negocio,
            $request->user(),
            $request->validated(),
        )->load([
            'categoria:id,negocio_id,name',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Insumo creado correctamente.',
            'data' => [
                'insumo' => (new InsumoResource($insumo))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Detalle de un insumo del negocio del usuario.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->insumos->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $insumo = $this->insumos->findForNegocio($negocio, $id);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'insumo' => (new InsumoResource($insumo))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Actualizar un insumo del negocio del usuario.
     */
    public function update(UpdateInsumoRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->insumos->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $insumo = $this->insumos->findForNegocio($negocio, $id);
        $insumo = $this->insumos->update($insumo, $request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Insumo actualizado correctamente.',
            'data' => [
                'insumo' => (new InsumoResource($insumo))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Activar o desactivar un insumo.
     */
    public function setStatus(ToggleInsumoStatusRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->insumos->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $insumo = $this->insumos->findForNegocio($negocio, $id);
        $status = (bool) $request->validated('status_insumo');
        $insumo = $this->insumos->setStatus($insumo, $request->user(), $status);

        return response()->json([
            'success' => true,
            'message' => $status
                ? 'Insumo activado correctamente.'
                : 'Insumo desactivado correctamente.',
            'data' => [
                'insumo' => (new InsumoResource($insumo))->resolve(),
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
