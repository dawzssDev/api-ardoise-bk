<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockInsumo\BulkUpsertStockInsumoRequest;
use App\Http\Requests\StockInsumo\UpdateStockInsumoRequest;
use App\Http\Requests\StockInsumo\UpsertStockInsumoRequest;
use App\Http\Resources\StockInsumoResource;
use App\Services\StockInsumoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StockInsumoController extends Controller
{
    public function __construct(
        private readonly StockInsumoService $stocks,
    ) {}

    /**
     * Listar stock de insumos por sucursal (incluye insumos sin registro aún).
     * Query: ?sucursal_id=1
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->stocks->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $sucursalId = (int) $request->query('sucursal_id', $request->query('sucursalId', 0));

        if ($sucursalId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Debes indicar sucursal_id.',
                'data' => null,
                'errors' => ['sucursal_id' => ['Debes indicar sucursal_id.']],
            ], 422);
        }

        $sucursal = $this->stocks->findSucursalForNegocio($negocio, $sucursalId);
        $paginator = $this->stocks->listForSucursal($negocio, $sucursal);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'sucursal' => [
                    'id' => $sucursal->id,
                    'type' => $sucursal->type,
                    'name' => $sucursal->name,
                ],
                'stocks' => StockInsumoResource::collection($paginator->items())->resolve(),
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
     * Crear o actualizar stock de un insumo en una sucursal.
     */
    public function upsert(UpsertStockInsumoRequest $request): JsonResponse
    {
        try {
            $negocio = $this->stocks->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $stock = $this->stocks->upsert($negocio, $request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Stock guardado correctamente.',
            'data' => [
                'stock' => (new StockInsumoResource($stock))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Guardar varios stocks de una sucursal (útil para la pantalla de captura).
     */
    public function bulkUpsert(BulkUpsertStockInsumoRequest $request): JsonResponse
    {
        try {
            $negocio = $this->stocks->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $data = $request->validated();
        $sucursal = $this->stocks->findSucursalForNegocio($negocio, (int) $data['sucursal_id']);
        $stocks = $this->stocks->upsertMany($negocio, $request->user(), $sucursal, $data['items']);

        return response()->json([
            'success' => true,
            'message' => 'Stocks guardados correctamente.',
            'data' => [
                'sucursal' => [
                    'id' => $sucursal->id,
                    'type' => $sucursal->type,
                    'name' => $sucursal->name,
                ],
                'stocks' => StockInsumoResource::collection($stocks)->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Detalle de un registro de stock.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->stocks->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $stock = $this->stocks->findForNegocio($negocio, $id);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'stock' => (new StockInsumoResource($stock))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Actualizar stock físico / mínimo de un registro existente.
     */
    public function update(UpdateStockInsumoRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->stocks->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $stock = $this->stocks->findForNegocio($negocio, $id);
        $stock = $this->stocks->update($stock, $request->user(), $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Stock actualizado correctamente.',
            'data' => [
                'stock' => (new StockInsumoResource($stock))->resolve(),
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
