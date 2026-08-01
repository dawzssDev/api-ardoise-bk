<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Negocio\UpdateNegocioRequest;
use App\Http\Resources\NegocioResource;
use App\Services\NegocioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NegocioController extends Controller
{
    public function __construct(
        private readonly NegocioService $negocios,
    ) {}

    /**
     * Ver el negocio del usuario autenticado.
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $negocio = $this->negocios->forUser($request->user());
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e->getStatusCode());
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'negocio' => (new NegocioResource($negocio))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Actualizar el negocio del usuario autenticado.
     */
    public function update(UpdateNegocioRequest $request): JsonResponse
    {
        try {
            $negocio = $this->negocios->forUser($request->user());
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e->getStatusCode());
        }

        $negocio = $this->negocios->update($negocio, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Negocio actualizado correctamente.',
            'data' => [
                'negocio' => (new NegocioResource($negocio))->resolve(),
            ],
            'errors' => null,
        ]);
    }
}
