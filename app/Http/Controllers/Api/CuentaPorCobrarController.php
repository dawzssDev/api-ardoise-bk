<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CuentaPorCobrar\PagarCuentaPorCobrarRequest;
use App\Http\Requests\CuentaPorCobrar\PagarLoteCuentaPorCobrarRequest;
use App\Http\Resources\CuentaPorCobrarResource;
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
     * Cuentas pagadas de la sucursal, agrupadas por empleado.
     * Staff: usa su sucursal. Maestro: requiere sucursal_id.
     */
    public function pagadas(Request $request): JsonResponse
    {
        try {
            $actor = $request->user();
            $negocio = $this->cuentas->negocioForUser($actor);
            $sucursalId = $this->requestSucursalId($request);
            $empleadoId = $request->filled('empleado_id') || $request->filled('empleadoId')
                ? (int) $request->input('empleado_id', $request->input('empleadoId'))
                : null;

            $payload = $this->cuentas->listPagadasBySucursal(
                $negocio,
                $actor,
                $sucursalId,
                $empleadoId,
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $empleados = array_map(static function (array $grupo): array {
            $grupo['cuentas'] = CuentaPorCobrarResource::collection($grupo['cuentas'])->resolve();

            return $grupo;
        }, $payload['empleados']);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'sucursal_id' => $payload['sucursal_id'],
                'status' => $payload['status'],
                'total_pagado' => $payload['total_pagado'],
                'cantidad' => $payload['cantidad'],
                'empleados' => $empleados,
            ],
            'errors' => null,
        ]);
    }

    /**
     * Marcar una cuenta como pagada (descuento en nómina).
     * Usuario maestro o staff con permiso cuentas_por_cobrar.
     */
    public function pagar(PagarCuentaPorCobrarRequest $request, int $id): JsonResponse
    {
        try {
            $actor = $request->user();
            $negocio = $this->cuentas->negocioForUser($actor);
            $cuenta = $this->cuentas->pagar($negocio, $actor, $id, $request->validated());
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
     * Marcar varias cuentas como pagadas en lote.
     * Usuario maestro o staff con permiso cuentas_por_cobrar.
     */
    public function pagarLote(PagarLoteCuentaPorCobrarRequest $request): JsonResponse
    {
        try {
            $actor = $request->user();
            $negocio = $this->cuentas->negocioForUser($actor);
            $cuentas = $this->cuentas->pagarLote($negocio, $actor, $request->validated());
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

    private function requestSucursalId(Request $request): ?int
    {
        foreach (['sucursal_id', 'sucursalId', 'SucursaliD', 'id_sucursal'] as $key) {
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
