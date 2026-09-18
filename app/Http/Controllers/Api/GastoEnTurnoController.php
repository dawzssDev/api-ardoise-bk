<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TurnoCaja\CreateGastoEnTurnoRequest;
use App\Http\Resources\GastoEnTurnoResource;
use App\Http\Resources\TurnoCajaResource;
use App\Models\GastoEnTurno;
use App\Services\TurnoCajaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class GastoEnTurnoController extends Controller
{
    public function __construct(
        private readonly TurnoCajaService $turnos,
    ) {}

    public function index(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $turno = $this->turnos->findForNegocio($negocio, $id);
            $paginator = $this->turnos->listGastos(
                $turno,
                perPage: (int) $request->integer('per_page', 50),
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'turno_id' => $turno->id,
                'gastos' => GastoEnTurnoResource::collection($paginator->items())->resolve(),
                'totales' => $this->turnos->sumGastosByTipo($turno),
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

    public function todas(Request $request): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $gastos = $this->turnos->listAllGastosForNegocio($negocio, $this->gastoFilters($request));
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'gastos' => GastoEnTurnoResource::collection($gastos)->resolve(),
                'totales' => $this->turnos->summarizeGastos($gastos),
            ],
            'errors' => null,
        ]);
    }

    public function store(CreateGastoEnTurnoRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $turno = $this->turnos->findForNegocio($negocio, $id);
            $gasto = $this->turnos->registrarGasto($turno, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return $this->gastoCreatedResponse($gasto);
    }

    public function storeActual(CreateGastoEnTurnoRequest $request): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $turno = $this->turnos->openTurnoForActor(
                $negocio,
                $request->user(),
                $request->filled('sucursal_id') ? (int) $request->integer('sucursal_id') : null,
            );

            if (! $turno) {
                throw new HttpException(422, 'Debes iniciar turno de caja antes de registrar gastos.');
            }

            $gasto = $this->turnos->registrarGasto($turno, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return $this->gastoCreatedResponse($gasto);
    }

    private function gastoCreatedResponse(GastoEnTurno $gasto): JsonResponse
    {
        $gasto->load([
            'cajero:id,username,sucursal_id',
            'user:id,name,email',
            'sucursal:id,negocio_id,type,name',
            'proveedor:id,negocio_id,name,legal_name,rfc,status',
        ]);
        $turno = $gasto->turnoCaja?->load([
            'cajera:id,negocio_id,username,sucursal_id,empleado_id,status',
            'user:id,name,email',
            'sucursal:id,negocio_id,type,name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Gasto registrado correctamente.',
            'data' => [
                'gasto' => (new GastoEnTurnoResource($gasto))->resolve(),
                'turno' => $turno ? (new TurnoCajaResource($turno))->resolve() : null,
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * @return array{
     *     sucursal_id: int|null,
     *     tipo_gasto: string|null,
     *     proveedor_id: int|null,
     *     descripcion: string|null,
     *     fecha: string|null,
     *     fecha_desde: string|null,
     *     fecha_hasta: string|null,
     *     turno_caja_id: int|null
     * }
     */
    private function gastoFilters(Request $request): array
    {
        $sucursalId = null;
        foreach (['sucursal_id', 'sucursalId', 'id_sucursal'] as $key) {
            if ($request->filled($key)) {
                $sucursalId = (int) $request->input($key);
                break;
            }
        }

        $tipo = $request->input('tipo_gasto', $request->input('tipo'));
        $descripcion = $request->input(
            'descripcion',
            $request->input('q', $request->input('search', $request->input('buscar')))
        );
        $proveedorId = null;
        foreach (['proveedor_id', 'proveedorId', 'id_proveedor'] as $key) {
            if ($request->filled($key)) {
                $proveedorId = (int) $request->input($key);
                break;
            }
        }

        $turnoId = null;
        foreach (['turno_caja_id', 'turno_id', 'turnoId'] as $key) {
            if ($request->filled($key)) {
                $turnoId = (int) $request->input($key);
                break;
            }
        }

        $fecha = $request->input('fecha');
        $periodo = mb_strtolower(trim((string) $request->input('periodo', '')));
        if ($periodo === 'hoy' || (is_string($fecha) && mb_strtolower(trim($fecha)) === 'hoy')) {
            $fecha = now()->toDateString();
        }

        return [
            'sucursal_id' => $sucursalId,
            'tipo_gasto' => is_string($tipo) && trim($tipo) !== '' ? trim($tipo) : null,
            'proveedor_id' => $proveedorId,
            'descripcion' => is_string($descripcion) && trim($descripcion) !== '' ? trim($descripcion) : null,
            'fecha' => is_string($fecha) && trim($fecha) !== '' ? trim($fecha) : null,
            'fecha_desde' => $request->filled('fecha_desde')
                ? (string) $request->input('fecha_desde')
                : ($request->filled('from') ? (string) $request->input('from') : null),
            'fecha_hasta' => $request->filled('fecha_hasta')
                ? (string) $request->input('fecha_hasta')
                : ($request->filled('to') ? (string) $request->input('to') : null),
            'turno_caja_id' => $turnoId,
        ];
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
