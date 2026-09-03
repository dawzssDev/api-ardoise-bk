<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Proveedor;
use App\Models\Staff;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ProveedorService
{
    use ResolvesNegocioFromActor;

    public function __construct(
        private readonly PlanLimitService $planLimits,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     legal_name?: string|null,
     *     rfc?: string|null,
     *     phone?: string|null,
     *     email?: string|null,
     *     address?: string|null,
     *     contact_name?: string|null,
     *     notes?: string|null,
     *     status?: int
     * }  $data
     */
    public function create(Negocio $negocio, User|Staff $actor, array $data): Proveedor
    {
        $this->planLimits->assertCanCreate($negocio, PlanLimitService::RESOURCE_PROVEEDORES);
        $auditId = $this->auditUserId($actor, $negocio);

        return $negocio->proveedores()->create([
            'name' => $data['name'],
            'legal_name' => $data['legal_name'] ?? null,
            'rfc' => $data['rfc'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'contact_name' => $data['contact_name'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => $data['status'] ?? Proveedor::STATUS_ACTIVO,
            'created_by' => $auditId,
            'updated_by' => $auditId,
        ]);
    }

    public function listForNegocio(Negocio $negocio, int $perPage = 15, ?int $status = null): LengthAwarePaginator
    {
        $query = $negocio->proveedores()
            ->with(['createdBy:id,name,email', 'updatedBy:id,name,email'])
            ->latest();

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    public function findForNegocio(Negocio $negocio, int $proveedorId): Proveedor
    {
        return $negocio->proveedores()
            ->with(['createdBy:id,name,email', 'updatedBy:id,name,email'])
            ->findOrFail($proveedorId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Proveedor $proveedor, User|Staff $actor, array $data): Proveedor
    {
        $proveedor->fill($data);
        $proveedor->updated_by = $this->auditUserId($actor, $proveedor->negocio);
        $proveedor->save();

        return $proveedor->refresh()->load([
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }

    /**
     * Baja lógica: status 1 (activo) → 0 (baja).
     */
    public function darDeBaja(Proveedor $proveedor, User|Staff $actor): Proveedor
    {
        if ((int) $proveedor->status === Proveedor::STATUS_BAJA) {
            throw new HttpException(422, 'El proveedor ya está dado de baja.');
        }

        $proveedor->status = Proveedor::STATUS_BAJA;
        $proveedor->updated_by = $this->auditUserId($actor, $proveedor->negocio);
        $proveedor->save();

        return $proveedor->refresh()->load([
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
        ]);
    }
}
