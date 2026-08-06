<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TurnoCaja\AbrirTurnoCajaRequest;
use App\Http\Requests\TurnoCaja\CerrarTurnoCajaRequest;
use App\Http\Resources\TurnoCajaResource;
use App\Http\Resources\VentaResource;
use App\Services\TurnoCajaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TurnoCajaController extends Controller
{
    public function __construct(
        private readonly TurnoCajaService $turnos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $paginator = $this->turnos->listForNegocio(
                $negocio,
                $request->user(),
                perPage: (int) $request->integer('per_page', 15),
                sucursalId: $request->filled('sucursal_id') ? (int) $request->integer('sucursal_id') : null,
                status: $request->filled('status') ? (string) $request->input('status') : null,
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'turnos' => TurnoCajaResource::collection($paginator->items())->resolve(),
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

    public function actual(Request $request): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $turno = $this->turnos->openTurnoForActor(
                $negocio,
                $request->user(),
                $request->filled('sucursal_id') ? (int) $request->integer('sucursal_id') : null,
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'turno' => $turno ? (new TurnoCajaResource($turno))->resolve() : null,
                'caja_abierta' => $turno !== null,
                'requiere_abrir_caja' => $turno === null,
            ],
            'errors' => null,
        ]);
    }

    public function store(AbrirTurnoCajaRequest $request): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $turno = $this->turnos->abrir($negocio, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Turno de caja abierto correctamente.',
            'data' => [
                'turno' => (new TurnoCajaResource($turno))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $turno = $this->turnos->findForNegocio($negocio, $id);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'turno' => (new TurnoCajaResource($turno))->resolve(),
                'preview_cierre' => $turno->isOpen()
                    ? $this->turnos->previewCierre($turno)
                    : null,
            ],
            'errors' => null,
        ]);
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $turno = $this->turnos->findForNegocio($negocio, $id);
            if (! $turno->isOpen()) {
                throw new HttpException(422, 'El turno ya está cerrado.');
            }
            $preview = $this->turnos->previewCierre($turno);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'turno_id' => $turno->id,
                'preview' => $preview,
            ],
            'errors' => null,
        ]);
    }

    public function cerrar(CerrarTurnoCajaRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $turno = $this->turnos->findForNegocio($negocio, $id);
            $data = $request->validated();
            $turno = $this->turnos->cerrar(
                $turno,
                $request->user(),
                (float) $data['efectivo_real'],
                $data['observaciones_cierre'] ?? null,
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Corte de caja realizado correctamente.',
            'data' => [
                'turno' => (new TurnoCajaResource($turno))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function ventas(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->turnos->negocioForUser($request->user());
            $turno = $this->turnos->findForNegocio($negocio, $id);
            $paginator = $this->turnos->listVentas(
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
                'ventas' => VentaResource::collection($paginator->items())->resolve(),
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
