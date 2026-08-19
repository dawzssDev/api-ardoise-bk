<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TurnoCaja\CreateDepositoEnTurnoRequest;
use App\Http\Resources\DepositoEnTurnoResource;
use App\Http\Resources\TurnoCajaResource;
use App\Models\DepositoEnTurno;
use App\Services\TurnoCajaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DepositoEfectivoEnTurnoController extends Controller
{
    public function __construct(
        private readonly TurnoCajaService $turnos,
    ) {}

    public function index(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $turno = $this->turnos->findForNegocio($negocio, $id);
            $paginator = $this->turnos->listDepositos(
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
                'depositos' => DepositoEnTurnoResource::collection($paginator->items())->resolve(),
                'totales' => [
                    'total' => $this->turnos->sumDepositos($turno),
                ],
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

    public function store(CreateDepositoEnTurnoRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $turno = $this->turnos->findForNegocio($negocio, $id);
            $deposito = $this->turnos->registrarDeposito($turno, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return $this->depositoCreatedResponse($deposito);
    }

    public function storeActual(CreateDepositoEnTurnoRequest $request): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $turno = $this->turnos->openTurnoForActor(
                $negocio,
                $request->user(),
                $request->filled('sucursal_id') ? (int) $request->integer('sucursal_id') : null,
            );

            if (! $turno) {
                throw new HttpException(422, 'Debes iniciar turno de caja antes de registrar depósitos.');
            }

            $deposito = $this->turnos->registrarDeposito($turno, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return $this->depositoCreatedResponse($deposito);
    }

    private function depositoCreatedResponse(DepositoEnTurno $deposito): JsonResponse
    {
        $deposito->load(['cajero:id,username,sucursal_id', 'user:id,name,email', 'sucursal:id,negocio_id,type,name']);
        $turno = $deposito->turnoCaja?->load([
            'cajera:id,negocio_id,username,sucursal_id,empleado_id,status',
            'user:id,name,email',
            'sucursal:id,negocio_id,type,name',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Depósito de efectivo registrado correctamente.',
            'data' => [
                'deposito' => (new DepositoEnTurnoResource($deposito))->resolve(),
                'turno' => $turno ? (new TurnoCajaResource($turno))->resolve() : null,
            ],
            'errors' => null,
        ], 201);
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
