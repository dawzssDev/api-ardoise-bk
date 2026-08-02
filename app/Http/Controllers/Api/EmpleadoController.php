<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Empleado\CreateEmpleadoRequest;
use App\Http\Requests\Empleado\ToggleEmpleadoStatusRequest;
use App\Http\Requests\Empleado\UpdateEmpleadoRequest;
use App\Http\Resources\EmpleadoResource;
use App\Services\EmpleadoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class EmpleadoController extends Controller
{
    public function __construct(
        private readonly EmpleadoService $empleados,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->empleados->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $paginator = $this->empleados->listForNegocio($negocio);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'empleados' => EmpleadoResource::collection($paginator->items())->resolve(),
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

    public function store(CreateEmpleadoRequest $request): JsonResponse
    {
        try {
            $negocio = $this->empleados->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $data = $request->validated();
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image');
        }

        $empleado = $this->empleados->create(
            $negocio,
            $request->user(),
            $data,
        )->load([
            'sucursal:id,negocio_id,type,name',
            'role:id,negocio_id,name,status',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Empleado creado correctamente.',
            'data' => [
                'empleado' => (new EmpleadoResource($empleado))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->empleados->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $empleado = $this->empleados->findForNegocio($negocio, $id);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'empleado' => (new EmpleadoResource($empleado))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function update(UpdateEmpleadoRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->empleados->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $data = $request->validated();
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image');
        }

        $empleado = $this->empleados->findForNegocio($negocio, $id);
        $empleado = $this->empleados->update($empleado, $request->user(), $data);

        return response()->json([
            'success' => true,
            'message' => 'Empleado actualizado correctamente.',
            'data' => [
                'empleado' => (new EmpleadoResource($empleado))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function setStatus(ToggleEmpleadoStatusRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->empleados->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $empleado = $this->empleados->findForNegocio($negocio, $id);
        $status = (string) $request->validated('status');
        $empleado = $this->empleados->setStatus($empleado, $request->user(), $status);

        return response()->json([
            'success' => true,
            'message' => 'Estatus del empleado actualizado correctamente.',
            'data' => [
                'empleado' => (new EmpleadoResource($empleado))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->empleados->negocioForUser($request->user());
            $empleado = $this->empleados->findForNegocio($negocio, $id);
            $this->empleados->delete($empleado);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Empleado eliminado correctamente.',
            'data' => null,
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
