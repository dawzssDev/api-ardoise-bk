<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoriaProducto\CreateCategoriaProductoRequest;
use App\Http\Requests\CategoriaProducto\UpdateCategoriaProductoRequest;
use App\Http\Resources\CategoriaProductoResource;
use App\Services\CategoriaProductoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CategoriaProductoController extends Controller
{
    public function __construct(
        private readonly CategoriaProductoService $categorias,
    ) {}

    /**
     * Listar categorías de productos del negocio del usuario.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->categorias->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $paginator = $this->categorias->listForNegocio($negocio);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'categorias' => CategoriaProductoResource::collection($paginator->items())->resolve(),
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
     * Registrar una categoría de productos del negocio del usuario.
     */
    public function store(CreateCategoriaProductoRequest $request): JsonResponse
    {
        try {
            $negocio = $this->categorias->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $categoria = $this->categorias->create($negocio, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Categoría creada correctamente.',
            'data' => [
                'categoria' => (new CategoriaProductoResource($categoria))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Detalle de una categoría de productos del negocio del usuario.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->categorias->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $categoria = $this->categorias->findForNegocio($negocio, $id);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'categoria' => (new CategoriaProductoResource($categoria))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Actualizar una categoría de productos del negocio del usuario.
     */
    public function update(UpdateCategoriaProductoRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->categorias->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $categoria = $this->categorias->findForNegocio($negocio, $id);
        $categoria = $this->categorias->update($categoria, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Categoría actualizada correctamente.',
            'data' => [
                'categoria' => (new CategoriaProductoResource($categoria))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Eliminar una categoría sin productos ligados.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->categorias->negocioForUser($request->user());
            $categoria = $this->categorias->findForNegocio($negocio, $id);
            $this->categorias->delete($categoria);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Categoría eliminada correctamente.',
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
