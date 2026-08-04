<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orden\CreateOrdenRequest;
use App\Http\Requests\Orden\UpdateOrdenDetalleStatusRequest;
use App\Http\Requests\Orden\UpdateOrdenStatusRequest;
use App\Http\Resources\OrdenDetalleResource;
use App\Http\Resources\OrdenResource;
use App\Services\OrdenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrdenController extends Controller
{
    public function __construct(
        private readonly OrdenService $ordenes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $paginator = $this->ordenes->listForNegocio(
            $negocio,
            perPage: (int) $request->integer('per_page', 15),
            sucursalId: $request->filled('sucursal_id') ? (int) $request->integer('sucursal_id') : null,
            status: $request->filled('status') ? (int) $request->integer('status') : (
                $request->filled('estatus') ? (int) $request->integer('estatus') : null
            ),
        );

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'ordenes' => OrdenResource::collection($paginator->items())->resolve(),
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
     * Cobrar / crear orden con sus detalles.
     */
    public function store(CreateOrdenRequest $request): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $orden = $this->ordenes->create($negocio, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Orden creada correctamente.',
            'data' => [
                'orden' => (new OrdenResource($orden))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $orden = $this->ordenes->findForNegocio($negocio, $id);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'orden' => (new OrdenResource($orden))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function setStatus(UpdateOrdenStatusRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $orden = $this->ordenes->findForNegocio($negocio, $id);
            $orden = $this->ordenes->setStatus(
                $orden,
                $request->user(),
                (int) $request->validated('status'),
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Estatus de orden actualizado.',
            'data' => [
                'orden' => (new OrdenResource($orden))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function setDetalleStatus(
        UpdateOrdenDetalleStatusRequest $request,
        int $id,
        int $detalleId,
    ): JsonResponse {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $orden = $this->ordenes->findForNegocio($negocio, $id);
            $detalle = $this->ordenes->setDetalleStatus(
                $orden,
                $detalleId,
                $request->user(),
                (int) $request->validated('status'),
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Estatus de detalle actualizado.',
            'data' => [
                'detalle' => (new OrdenDetalleResource($detalle))->resolve(),
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
