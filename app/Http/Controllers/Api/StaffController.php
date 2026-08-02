<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\CreateStaffRequest;
use App\Http\Requests\Staff\ToggleStaffStatusRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Services\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class StaffController extends Controller
{
    public function __construct(
        private readonly StaffService $staff,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $negocio = $this->staff->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $paginator = $this->staff->listForNegocio($negocio);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'staff' => StaffResource::collection($paginator->items())->resolve(),
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

    public function store(CreateStaffRequest $request): JsonResponse
    {
        try {
            $negocio = $this->staff->negocioForUser($request->user());
            $staff = $this->staff->create(
                $negocio,
                $request->user(),
                $request->validated(),
            )->load([
                'sucursal:id,negocio_id,type,name',
                'role:id,negocio_id,name,status',
                'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname,employee_number,status',
                'createdBy:id,name,email',
                'updatedBy:id,name,email',
            ]);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Usuario staff creado correctamente.',
            'data' => [
                'staff' => (new StaffResource($staff))->resolve(),
            ],
            'errors' => null,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->staff->negocioForUser($request->user());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        $staff = $this->staff->findForNegocio($negocio, $id);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'staff' => (new StaffResource($staff))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function update(UpdateStaffRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->staff->negocioForUser($request->user());
            $staff = $this->staff->findForNegocio($negocio, $id);
            $staff = $this->staff->update($staff, $request->user(), $request->validated());
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Usuario staff actualizado correctamente.',
            'data' => [
                'staff' => (new StaffResource($staff))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function setStatus(ToggleStaffStatusRequest $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->staff->negocioForUser($request->user());
            $staff = $this->staff->findForNegocio($negocio, $id);
            $status = (bool) $request->validated('status');
            $staff = $this->staff->setStatus($staff, $request->user(), $status);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => $status
                ? 'Usuario staff activado correctamente.'
                : 'Usuario staff desactivado correctamente.',
            'data' => [
                'staff' => (new StaffResource($staff))->resolve(),
            ],
            'errors' => null,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            $negocio = $this->staff->negocioForUser($request->user());
            $staff = $this->staff->findForNegocio($negocio, $id);
            $this->staff->delete($staff);
        } catch (HttpException $e) {
            return $this->errorResponse($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Usuario staff eliminado correctamente.',
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
