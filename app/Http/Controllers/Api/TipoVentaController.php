<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TipoVenta\CreateTipoVentaRequest;
use App\Http\Requests\TipoVenta\UpdateTipoVentaRequest;
use App\Http\Resources\TipoVentaResource;
use App\Services\TipoVentaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TipoVentaController extends Controller
{
    public function __construct(
        private readonly TipoVentaService $tiposVenta,
    ) {}

    /**
     * Listar tipos de venta del negocio del usuario.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->tiposVenta->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $paginator = $this->tiposVenta->listForNegocio($negocio);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'tipos_venta' => TipoVentaResource::collection($paginator->items())->resolve(),
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
     * Registrar un tipo de venta del negocio del usuario.
     */
    public function store(CreateTipoVentaRequest $request): JsonResponse
    {
        try {
            $negocio = $this->tiposVenta->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $tipoVenta = $this->tiposVenta->create($negocio, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Tipo de venta creado correctamente.',
            'data' => [
                'tipo_venta' => (new TipoVentaResource($tipoVenta))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Detalle de un tipo de venta del negocio del usuario.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->tiposVenta->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $tipoVenta = $this->tiposVenta->findForNegocio($negocio, $id);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'tipo_venta' => (new TipoVentaResource($tipoVenta))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Actualizar un tipo de venta del negocio del usuario.
     */
    public function update(UpdateTipoVentaRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->tiposVenta->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $tipoVenta = $this->tiposVenta->findForNegocio($negocio, $id);
        $tipoVenta = $this->tiposVenta->update($tipoVenta, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Tipo de venta actualizado correctamente.',
            'data' => [
                'tipo_venta' => (new TipoVentaResource($tipoVenta))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Eliminar un tipo de venta sin órdenes ligadas.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->tiposVenta->negocioForUser($request->user());
            $tipoVenta = $this->tiposVenta->findForNegocio($negocio, $id);
            $this->tiposVenta->delete($tipoVenta);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tipo de venta eliminado correctamente.',
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
