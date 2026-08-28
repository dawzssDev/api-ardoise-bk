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
     * Listar productos activos del negocio.
     *
     * Query:
     * - categoria_producto_id / categoriaProductoId / categoria_id: filtra por categoría
     * - Por defecto devuelve TODOS los productos activos (o todos los de la categoría).
     * - paginate=1 + per_page / page: activa paginación opcional
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->productos->negocioForUser($request->user());
            $categoriaId = $this->requestCategoriaProductoId($request);
            $perPage = null;

            if ($request->boolean('paginate') || $request->boolean('paginated')) {
                $perPage = (int) $request->input('per_page', $request->input('perPage', 15));
            }

            $paginator = $this->productos->listForNegocio(
                $negocio,
                $perPage,
                $categoriaId,
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

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
                    'categoria_producto_id' => $categoriaId,
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
     * Baja lógica de producto (status = 0).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->productos->negocioForUser($request->user());
            $producto = $this->productos->findForNegocio($negocio, $id);
            $producto = $this->productos->delete($producto, $request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Producto dado de baja correctamente.',
            'data' => [
                'producto' => (new ProductoResource($producto))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    private function requestCategoriaProductoId(Request $request): ?int
    {
        foreach (['categoria_producto_id', 'categoriaProductoId', 'categoria_id', 'categoriaId'] as $key) {
            if ($request->filled($key)) {
                return (int) $request->input($key);
            }
        }

        return null;
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
