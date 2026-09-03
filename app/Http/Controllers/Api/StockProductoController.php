<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockProducto\BulkUpsertStockProductoRequest;
use App\Http\Requests\StockProducto\ToggleStockProductoActiveRequest;
use App\Http\Requests\StockProducto\UpdateStockProductoRequest;
use App\Http\Requests\StockProducto\UpsertStockProductoRequest;
use App\Http\Resources\StockProductoResource;
use App\Models\StockProducto;
use App\Services\StockProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StockProductoController extends Controller
{
    public function __construct(
        private readonly StockProductoService $stocks,
    ) {}

    /**
     * Listar stock de productos por sucursal (incluye productos sin registro aún).
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
        $soloActivos = StockProducto::normalizeActivo(
            $request->query('activo', $request->query('disponible', $request->query('is_active')))
        );
        $paginator = $this->stocks->listForSucursal($negocio, $sucursal, soloActivos: $soloActivos);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'sucursal' => [
                    'id' => $sucursal->id,
                    'type' => $sucursal->type,
                    'name' => $sucursal->name,
                ],
                'stocks' => StockProductoResource::collection($paginator->items())->resolve(),
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
     * Crear o actualizar stock de un producto en una sucursal.
     */
    public function upsert(UpsertStockProductoRequest $request): JsonResponse
    {
        try {
            $negocio = $this->stocks->negocioForUser($request->user());
            $stock = $this->stocks->upsert($negocio, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stock guardado correctamente.',
            'data' => [
                'stock' => (new StockProductoResource($stock))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Guardar varios stocks de una sucursal (útil para la pantalla de captura).
     */
    public function bulkUpsert(BulkUpsertStockProductoRequest $request): JsonResponse
    {
        try {
            $negocio = $this->stocks->negocioForUser($request->user());
            $data = $request->validated();
            $sucursal = $this->stocks->findSucursalForNegocio($negocio, (int) $data['sucursal_id']);
            $stocks = $this->stocks->upsertMany($negocio, $request->user(), $sucursal, $data['items']);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Stocks guardados correctamente.',
            'data' => [
                'sucursal' => [
                    'id' => $sucursal->id,
                    'type' => $sucursal->type,
                    'name' => $sucursal->name,
                ],
                'stocks' => StockProductoResource::collection($stocks)->resolve(),
            ],
            'errors' => null,
        ]);
    }

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
                'stock' => (new StockProductoResource($stock))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function update(UpdateStockProductoRequest $request, int $id): JsonResponse
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
                'stock' => (new StockProductoResource($stock))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Activar o desactivar un producto en la sucursal.
     */
    public function setActive(ToggleStockProductoActiveRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->stocks->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $isActive = (bool) $request->validated('is_active');
        $stock = $this->stocks->findForNegocio($negocio, $id);
        $stock = $this->stocks->setActive($stock, $request->user(), $isActive);

        return response()->json([
            'success' => true,
            'message' => $isActive
                ? 'Producto activado en la sucursal.'
                : 'Producto desactivado en la sucursal.',
            'data' => [
                'stock' => (new StockProductoResource($stock))->resolve(),
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
