<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Orden\AgregarOrdenDetallesRequest;
use App\Http\Requests\Orden\CancelarOrdenDetallesRequest;
use App\Http\Requests\Orden\CobrarOrdenRequest;
use App\Http\Requests\Orden\CreateOrdenRequest;
use App\Http\Requests\Orden\ListOrdenesPorFechaRequest;
use App\Http\Requests\Orden\ListOrdenesTurnoRequest;
use App\Http\Requests\Orden\UpdateOrdenDetalleEntregaRequest;
use App\Http\Requests\Orden\UpdateOrdenDetalleStatusRequest;
use App\Http\Requests\Orden\UpdateOrdenStatusRequest;
use App\Http\Resources\OrdenDetalleResource;
use App\Http\Resources\OrdenResource;
use App\Http\Resources\TurnoCajaResource;
use App\Models\Orden;
use App\Services\OrdenPorFechaService;
use App\Services\OrdenService;
use App\Services\OrdenTurnoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrdenController extends Controller
{
    public function __construct(
        private readonly OrdenService $ordenes,
        private readonly OrdenPorFechaService $ordenesPorFecha,
        private readonly OrdenTurnoService $ordenesTurno,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $paginator = $this->ordenes->listForNegocio(
                $negocio,
                $request->user(),
                perPage: (int) $request->integer('per_page', 15),
                sucursalId: $this->requestSucursalId($request),
                status: $request->filled('status') ? (int) $request->integer('status') : (
                    $request->filled('estatus') ? (int) $request->integer('estatus') : null
                ),
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'ordenes' => OrdenResource::collection($paginator->items())->resolve(),
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
     * Tablero de cocina (KDS).
     * Maestro: ?sucursal_id= requerido (selector). Staff: usa su sucursal.
     */
    public function cocina(Request $request): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $board = $this->ordenes->kitchenBoard(
                $negocio,
                $request->user(),
                $this->requestSucursalId($request),
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $nuevo = OrdenResource::collection($board['nuevo'])->resolve();
        $enPreparacion = OrdenResource::collection($board['en_preparacion'])->resolve();
        $listo = OrdenResource::collection($board['listo'])->resolve();

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'sucursal' => $board['sucursal'],
                'nuevo' => $nuevo,
                'en_preparacion' => $enPreparacion,
                'listo' => $listo,
                // aliases front
                'activos' => $nuevo,
                'en_proceso' => $enPreparacion,
                'finalizados' => $listo,
            ],
            'errors' => null,
        ]);
    }

    /**
     * Órdenes de hoy de la sucursal (todas + buckets Nuevo / En preparación / Listo).
     * Maestro: ?sucursal_id= requerido. Staff: usa su sucursal.
     */
    public function hoy(Request $request): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $payload = $this->ordenes->listHoy(
                $negocio,
                $request->user(),
                $this->requestSucursalId($request),
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return $this->ordenesPorFechaResponse($payload);
    }

    /**
     * Órdenes de un día (default: hoy). Query: ?fecha=YYYY-MM-DD&sucursal_id=
     * Maestro: sucursal_id requerido. Staff: usa su sucursal.
     */
    public function porFecha(ListOrdenesPorFechaRequest $request): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $payload = $this->ordenesPorFecha->list(
                $negocio,
                $request->user(),
                $request->validated('fecha'),
                $request->filled('sucursal_id') ? (int) $request->validated('sucursal_id') : null,
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return $this->ordenesPorFechaResponse($payload);
    }

    /**
     * Órdenes del turno de caja: pendientes por pagar, pagadas y canceladas.
     * Query: ?sucursal_id= (maestro) y/o ?turno_id=
     */
    public function turno(ListOrdenesTurnoRequest $request): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $payload = $this->ordenesTurno->list(
                $negocio,
                $request->user(),
                $request->filled('sucursal_id') ? (int) $request->validated('sucursal_id') : null,
                $request->filled('turno_id') ? (int) $request->validated('turno_id') : null,
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'turno' => (new TurnoCajaResource($payload['turno']))->resolve(),
                'sucursal' => $payload['sucursal'],
                'ordenes' => OrdenResource::collection($payload['ordenes'])->resolve(),
                'pendientes_por_pagar' => OrdenResource::collection($payload['pendientes_por_pagar'])->resolve(),
                'pagadas' => OrdenResource::collection($payload['pagadas'])->resolve(),
                'canceladas' => OrdenResource::collection($payload['canceladas'])->resolve(),
                'resumen' => $payload['resumen'],
            ],
            'errors' => null,
        ]);
    }

    /**
     * Cobrar / crear orden con sus detalles.
     */
    public function store(CreateOrdenRequest $request): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $orden = $this->ordenes->create($negocio, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Orden creada correctamente.',
            'data' => [
                'orden' => (new OrdenResource($orden))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Agregar productos a una orden pendiente de pago (orden en mesa).
     */
    public function addDetalles(AgregarOrdenDetallesRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $orden = $this->ordenes->findForNegocio($negocio, $id);
            $orden = $this->ordenes->addDetalles($orden, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Productos agregados a la orden.',
            'data' => [
                'orden' => (new OrdenResource($orden))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Cobrar una orden pendiente (cuando el cliente solicita la cuenta).
     */
    public function cobrar(CobrarOrdenRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $orden = $this->ordenes->findForNegocio($negocio, $id);
            $orden = $this->ordenes->cobrar($orden, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Orden cobrada correctamente.',
            'data' => [
                'orden' => (new OrdenResource($orden))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $orden = $this->ordenes->findForNegocio($negocio, $id);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'orden' => (new OrdenResource($orden))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function setStatus(UpdateOrdenStatusRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $orden = $this->ordenes->findForNegocio($negocio, $id);
            $orden = $this->ordenes->setStatus(
                $orden,
                $request->user(),
                (int) $request->validated('status'),
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Estatus de orden actualizado.',
            'data' => [
                'orden' => (new OrdenResource($orden))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function setDetalleStatus(
        UpdateOrdenDetalleStatusRequest $request,
        int $id,
        int $detalleId,
    ): JsonResponse {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $orden = $this->ordenes->findForNegocio($negocio, $id);
            $detalle = $this->ordenes->setDetalleStatus(
                $orden,
                $detalleId,
                $request->user(),
                (int) $request->validated('status'),
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Estatus de detalle actualizado.',
            'data' => [
                'detalle' => (new OrdenDetalleResource($detalle))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Marcar un único producto del detalle como entregado (2) o sin entregar (1).
     */
    public function setDetalleEntrega(
        UpdateOrdenDetalleEntregaRequest $request,
        int $id,
        int $detalleId,
    ): JsonResponse {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $orden = $this->ordenes->findForNegocio($negocio, $id);
            $detalle = $this->ordenes->setDetalleEntrega(
                $orden,
                $detalleId,
                $request->user(),
                (int) $request->validated('status_entregado'),
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Estatus de entrega actualizado.',
            'data' => [
                'detalle' => (new OrdenDetalleResource($detalle))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Cancelar uno o más productos de la orden (status detalle = 5).
     * El price del detalle queda negativo y se recalcula el total de la orden.
     */
    public function cancelarDetalles(CancelarOrdenDetallesRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->ordenes->negocioForUser($request->user());
            $orden = $this->ordenes->findForNegocio($negocio, $id);
            $orden = $this->ordenes->cancelarDetalles(
                $orden,
                $request->user(),
                $request->validated('detalle_ids'),
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Producto(s) cancelado(s) correctamente.',
            'data' => [
                'orden' => (new OrdenResource($orden))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    private function requestSucursalId(Request $request): ?int
    {
        if ($request->filled('sucursal_id')) {
            return (int) $request->integer('sucursal_id');
        }

        if ($request->filled('sucursalId')) {
            return (int) $request->integer('sucursalId');
        }

        return null;
    }

    /**
     * @param  array{
     *     fecha: string,
     *     sucursal: array{id: int, type: string, name: string},
     *     ordenes: list<Orden>,
     *     nuevo: list<Orden>,
     *     en_preparacion: list<Orden>,
     *     listo: list<Orden>
     * }  $payload
     */
    private function ordenesPorFechaResponse(array $payload): JsonResponse
    {
        $nuevo = OrdenResource::collection($payload['nuevo'])->resolve();
        $enPreparacion = OrdenResource::collection($payload['en_preparacion'])->resolve();
        $listo = OrdenResource::collection($payload['listo'])->resolve();

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'fecha' => $payload['fecha'],
                'sucursal' => $payload['sucursal'],
                'ordenes' => OrdenResource::collection($payload['ordenes'])->resolve(),
                'nuevo' => $nuevo,
                'en_preparacion' => $enPreparacion,
                'listo' => $listo,
                'activos' => $nuevo,
                'en_proceso' => $enPreparacion,
                'finalizados' => $listo,
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
