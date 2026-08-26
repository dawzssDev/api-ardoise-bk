<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaeCuentaContaSuc\CreateMaeCuentaContaSucRequest;
use App\Http\Requests\MaeCuentaContaSuc\UpdateMaeCuentaContaSucRequest;
use App\Http\Resources\MaeCuentaContaSucResource;
use App\Services\MaeCuentaContaSucService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MaeCuentaContaSucController extends Controller
{
    public function __construct(
        private readonly MaeCuentaContaSucService $cuentas,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->cuentas->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $paginator = $this->cuentas->listForNegocio(
            $negocio,
            perPage: (int) $request->integer('per_page', 15),
            status: $request->filled('status') ? (int) $request->integer('status') : null,
            tipoCuenta: $request->input('tipo_cuenta', $request->input('tipoCuenta')),
            sucursalId: $request->filled('sucursal_id') || $request->filled('SucursaliD')
                ? (int) $request->input('sucursal_id', $request->input('SucursaliD'))
                : null,
            includeDeleted: $request->boolean('include_deleted'),
        );

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'cuentas' => MaeCuentaContaSucResource::collection($paginator->items())->resolve(),
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

    public function store(CreateMaeCuentaContaSucRequest $request): JsonResponse
    {
        try {
            $negocio = $this->cuentas->negocioForUser($request->user());
            $cuenta = $this->cuentas->create(
                $negocio,
                $request->user(),
                $request->validated(),
            )->load([
                'sucursal:id,negocio_id,type,name',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ]);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cuenta contable creada correctamente.',
            'data' => [
                'cuenta' => (new MaeCuentaContaSucResource($cuenta))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->cuentas->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $cuenta = $this->cuentas->findForNegocio($negocio, $id);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'cuenta' => (new MaeCuentaContaSucResource($cuenta))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function update(UpdateMaeCuentaContaSucRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->cuentas->negocioForUser($request->user());
            $cuenta = $this->cuentas->findForNegocio($negocio, $id);
            $cuenta = $this->cuentas->update($cuenta, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cuenta contable actualizada correctamente.',
            'data' => [
                'cuenta' => (new MaeCuentaContaSucResource($cuenta))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->cuentas->negocioForUser($request->user());
            $cuenta = $this->cuentas->findForNegocio($negocio, $id);
            $cuenta = $this->cuentas->darDeBaja($cuenta, $request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cuenta contable eliminada correctamente.',
            'data' => [
                'cuenta' => (new MaeCuentaContaSucResource($cuenta))->resolve(),
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
