<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrdenPagoResource;
use App\Services\OrdenPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrdenPagoController extends Controller
{
    public function __construct(
        private readonly OrdenPagoService $pagos,
    ) {}

    /**
     * Pagos de una orden (GET /api/ordenes/{id}/pagos).
     */
    public function index(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->pagos->negocioForUser($request->user());
            $payload = $this->pagos->listForOrden($negocio, $request->user(), $id);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        /** @var \App\Models\Orden $orden */
        $orden = $payload['orden'];

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'orden' => [
                    'id' => $orden->id,
                    'numero_orden' => $orden->numeroOrden(),
                    'tipo_pago' => $orden->payment_type,
                    'payment_type' => $orden->payment_type,
                    'total' => (string) $orden->total,
                ],
                'pagos' => OrdenPagoResource::collection($payload['pagos'])->resolve(),
                'resumen' => $payload['resumen'],
            ],
            'errors' => null,
        ]);
    }

    /**
     * Detalle de un pago (GET /api/ordenes/{id}/pagos/{pagoId}).
     */
    public function show(Request $request, int $id, int $pagoId): JsonResponse
    {
        try {
            $negocio = $this->pagos->negocioForUser($request->user());
            $pago = $this->pagos->findForOrden($negocio, $request->user(), $id, $pagoId);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'pago' => (new OrdenPagoResource($pago))->resolve(),
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
