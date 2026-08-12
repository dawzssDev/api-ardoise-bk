<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CuentaPorCobrar\PagarCuentaPorCobrarRequest;
use App\Http\Requests\CuentaPorCobrar\PagarLoteCuentaPorCobrarRequest;
use App\Http\Resources\CuentaPorCobrarResource;
use App\Models\User;
use App\Services\CuentaPorCobrarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CuentaPorCobrarController extends Controller
{
    public function __construct(
        private readonly CuentaPorCobrarService $cuentas,
    ) {}

    /**
     * Listar cuentas por cobrar del negocio (filtros: empleado_id, status, sucursal_id).
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->cuentas->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $paginator = $this->cuentas->listForNegocio($negocio, [
            'empleado_id' => $request->query('empleado_id'),
            'status' => $request->query('status'),
            'sucursal_id' => $request->query('sucursal_id'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'cuentas' => CuentaPorCobrarResource::collection($paginator->items())->resolve(),
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
     * Total pendiente agrupado por empleado.
     */
    public function resumen(Request $request): JsonResponse
    {
        try {
            $negocio = $this->cuentas->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'resumen' => $this->cuentas->resumenPendiente($negocio),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Marcar una cuenta como pagada (descuento en nómina). Solo maestro.
     */
    public function pagar(PagarCuentaPorCobrarRequest $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->errorResponse(new HttpException(403, 'Solo el dueño puede marcar cuentas como pagadas.'));
        }

        try {
            $negocio = $this->cuentas->negocioForUser($user);
            $cuenta = $this->cuentas->pagar($negocio, $user, $id, $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cuenta marcada como pagada.',
            'data' => [
                'cuenta' => (new CuentaPorCobrarResource($cuenta))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Marcar varias cuentas como pagadas en lote. Solo maestro.
     */
    public function pagarLote(PagarLoteCuentaPorCobrarRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->errorResponse(new HttpException(403, 'Solo el dueño puede marcar cuentas como pagadas.'));
        }

        try {
            $negocio = $this->cuentas->negocioForUser($user);
            $cuentas = $this->cuentas->pagarLote($negocio, $user, $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cuentas marcadas como pagadas.',
            'data' => [
                'cuentas' => CuentaPorCobrarResource::collection($cuentas)->resolve(),
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
