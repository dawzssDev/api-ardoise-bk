<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaeCuentaContaSuc\CreateMaeCuentaContaSucDetalleRequest;
use App\Http\Requests\MaeCuentaContaSuc\UpdateMaeCuentaContaSucDetalleRequest;
use App\Http\Resources\MaeCuentaContaSucDetalleResource;
use App\Models\MaeCuentaContaSucDetalle;
use App\Services\MaeCuentaContaSucDetalleService;
use App\Services\MaeCuentaContaSucService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MaeCuentaContaSucDetalleController extends Controller
{
    public function __construct(
        private readonly MaeCuentaContaSucDetalleService $detalles,
        private readonly MaeCuentaContaSucService $cuentas,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->detalles->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $cuentaId = $request->input('mae_cuenta_conta_suc_id', $request->input('idMaeCuentaContaSuc'));

        $paginator = $this->detalles->listForNegocio(
            $negocio,
            perPage: (int) $request->integer('per_page', 15),
            cuentaId: $cuentaId !== null ? (int) $cuentaId : null,
            tipoMovimiento: $request->input('tipo_movimiento', $request->input('tipoMovimiento')),
            status: $request->filled('status') ? (int) $request->integer('status') : null,
            includeDeleted: $request->boolean('include_deleted'),
        );

        return $this->listResponse($paginator);
    }

    public function indexByCuenta(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->cuentas->negocioForUser($request->user());
            $cuenta = $this->cuentas->findForNegocio($negocio, $id);
            $paginator = $this->detalles->listForCuenta(
                $cuenta,
                perPage: (int) $request->integer('per_page', 15),
                includeDeleted: $request->boolean('include_deleted'),
            );
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return $this->listResponse($paginator);
    }

    public function store(CreateMaeCuentaContaSucDetalleRequest $request): JsonResponse
    {
        try {
            $negocio = $this->cuentas->negocioForUser($request->user());
            $cuentaId = (int) $request->validated('mae_cuenta_conta_suc_id');
            $cuenta = $this->cuentas->findForNegocio($negocio, $cuentaId);
            $detalle = $this->detalles->create($cuenta, $request->user(), $request->validated())
                ->load([
                    'cuenta:id,negocio_id,tipo_cuenta,sucursal_id,titulo_cuenta,status,deleted',
                    'cuentaOrigen:id,negocio_id,tipo_cuenta,sucursal_id,titulo_cuenta,status,deleted',
                    'cuentaDestino:id,negocio_id,tipo_cuenta,sucursal_id,titulo_cuenta,status,deleted',
                    'createdBy:id,name,email',
                    'updatedBy:id,name,email',
                ]);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return $this->createdResponse($detalle);
    }

    public function storeByCuenta(CreateMaeCuentaContaSucDetalleRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->cuentas->negocioForUser($request->user());
            $cuenta = $this->cuentas->findForNegocio($negocio, $id);
            $detalle = $this->detalles->create($cuenta, $request->user(), $request->validated())
                ->load([
                    'cuenta:id,negocio_id,tipo_cuenta,sucursal_id,titulo_cuenta,status,deleted',
                    'cuentaOrigen:id,negocio_id,tipo_cuenta,sucursal_id,titulo_cuenta,status,deleted',
                    'cuentaDestino:id,negocio_id,tipo_cuenta,sucursal_id,titulo_cuenta,status,deleted',
                    'createdBy:id,name,email',
                    'updatedBy:id,name,email',
                ]);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return $this->createdResponse($detalle);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->detalles->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $detalle = $this->detalles->findForNegocio($negocio, $id);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'detalle' => (new MaeCuentaContaSucDetalleResource($detalle))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function update(UpdateMaeCuentaContaSucDetalleRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->detalles->negocioForUser($request->user());
            $detalle = $this->detalles->findForNegocio($negocio, $id);
            $detalle = $this->detalles->update($detalle, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Movimiento actualizado correctamente.',
            'data' => [
                'detalle' => (new MaeCuentaContaSucDetalleResource($detalle))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->detalles->negocioForUser($request->user());
            $detalle = $this->detalles->findForNegocio($negocio, $id);
            $detalle = $this->detalles->darDeBaja($detalle, $request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Movimiento eliminado correctamente.',
            'data' => [
                'detalle' => (new MaeCuentaContaSucDetalleResource($detalle))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    private function createdResponse(MaeCuentaContaSucDetalle $detalle): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Movimiento registrado correctamente.',
            'data' => [
                'detalle' => (new MaeCuentaContaSucDetalleResource($detalle))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    private function listResponse(LengthAwarePaginator $paginator): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'detalles' => MaeCuentaContaSucDetalleResource::collection($paginator->items())->resolve(),
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
