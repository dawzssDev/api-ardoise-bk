<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Producto\CreateProductoRequest;
use App\Http\Requests\Producto\UpdateProductoRequest;
use App\Http\Resources\ProductoResource;
use App\Services\ProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProductoController extends Controller
{
    public function __construct(
        private readonly ProductoService $productos,
    ) {}

    /**
     * Listar productos del negocio del usuario.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->productos->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $paginator = $this->productos->listForNegocio($negocio);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'productos' => ProductoResource::collection($paginator->items())->resolve(),
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
     * Registrar un producto del negocio del usuario.
     * Acepta multipart/form-data (campo image/imagen).
     */
    public function store(CreateProductoRequest $request): JsonResponse
    {
        try {
            $negocio = $this->productos->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $data = $request->validated();
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image');
        }

        $producto = $this->productos->create(
            $negocio,
            $request->user(),
            $data,
        )->load([
            'categoria:id,negocio_id,name',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Producto creado correctamente.',
            'data' => [
                'producto' => (new ProductoResource($producto))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Detalle de un producto del negocio del usuario.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->productos->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $producto = $this->productos->findForNegocio($negocio, $id);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'producto' => (new ProductoResource($producto))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Actualizar un producto.
     * Preferir POST multipart si se envía imagen (PUT + files suele fallar en PHP).
     */
    public function update(UpdateProductoRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->productos->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $data = $request->validated();
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image');
        }

        $producto = $this->productos->findForNegocio($negocio, $id);
        $producto = $this->productos->update($producto, $request->user(), $data);

        return response()->json([
            'success' => true,
            'message' => 'Producto actualizado correctamente.',
            'data' => [
                'producto' => (new ProductoResource($producto))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Eliminar un producto del negocio del usuario.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->productos->negocioForUser($request->user());
            $producto = $this->productos->findForNegocio($negocio, $id);
            $this->productos->delete($producto);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Producto eliminado correctamente.',
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
