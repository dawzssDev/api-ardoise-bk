<?php

namespace App\Services;

use App\Models\CuentaPorCobrar;
use App\Models\Negocio;
use App\Models\Staff;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CuentaPorCobrarService
{
    use ResolvesNegocioFromActor;

    /**
     * @param  array{
     *     empleado_id?: int|null,
     *     status?: string|null,
     *     sucursal_id?: int|null
     * }  $filters
     */
    public function listForNegocio(
        Negocio $negocio,
        array $filters = [],
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = $negocio->cuentasPorCobrar()
            ->with([
                'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname',
                'sucursal:id,negocio_id,name,type',
                'orden:id,negocio_id,sucursal_id,order_number',
            ])
            ->latest('fecha_generado');

        if (! empty($filters['empleado_id'])) {
            $query->where('empleado_id', (int) $filters['empleado_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['sucursal_id'])) {
            $query->where('sucursal_id', (int) $filters['sucursal_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Total pendiente agrupado por empleado.
     *
     * @return list<array{empleado_id: int, empleado: string, total_pendiente: string, cantidad: int}>
     */
    public function resumenPendiente(Negocio $negocio): array
    {
        $rows = $negocio->cuentasPorCobrar()
            ->selectRaw('empleado_id, COUNT(*) as cantidad, SUM(monto) as total_pendiente')
            ->where('status', CuentaPorCobrar::STATUS_PENDIENTE)
            ->groupBy('empleado_id')
            ->get();

        $empleados = $negocio->empleados()
            ->whereIn('id', $rows->pluck('empleado_id'))
            ->get(['id', 'first_name', 'paternal_surname', 'maternal_surname'])
            ->keyBy('id');

        return $rows->map(function (CuentaPorCobrar $row) use ($empleados) {
            $empleado = $empleados->get($row->empleado_id);

            return [
                'empleado_id' => (int) $row->empleado_id,
                'empleado' => $empleado?->fullName() ?? '',
                'total_pendiente' => number_format((float) $row->total_pendiente, 2, '.', ''),
                'cantidad' => (int) $row->cantidad,
            ];
        })->values()->all();
    }

    public function findForNegocio(Negocio $negocio, int $id): CuentaPorCobrar
    {
        return $negocio->cuentasPorCobrar()
            ->with([
                'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname',
                'sucursal:id,negocio_id,name,type',
                'orden:id,negocio_id,sucursal_id,order_number',
            ])
            ->findOrFail($id);
    }

    /**
     * Cuentas pagadas de una sucursal, agrupadas por empleado.
     *
     * Staff: usa su sucursal. Maestro: debe enviar sucursal_id.
     *
     * @return array{
     *     sucursal_id: int,
     *     status: string,
     *     total_pagado: string,
     *     cantidad: int,
     *     empleados: list<array{
     *         empleado_id: int,
     *         empleado: string,
     *         total_pagado: string,
     *         cantidad: int,
     *         cuentas: list<CuentaPorCobrar>
     *     }>
     * }
     */
    public function listPagadasBySucursal(
        Negocio $negocio,
        User|Staff $actor,
        ?int $sucursalId = null,
        ?int $empleadoId = null,
    ): array {
        $sucursalId = $this->resolveSucursalIdForList($negocio, $actor, $sucursalId);

        $query = $negocio->cuentasPorCobrar()
            ->with([
                'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname',
                'sucursal:id,negocio_id,name,type',
                'orden:id,negocio_id,sucursal_id,order_number',
            ])
            ->where('sucursal_id', $sucursalId)
            ->where('status', CuentaPorCobrar::STATUS_PAGADO)
            ->latest('fecha_pagado')
            ->latest('id');

        if ($empleadoId !== null && $empleadoId > 0) {
            $query->where('empleado_id', $empleadoId);
        }

        $cuentas = $query->get();

        $empleados = $cuentas
            ->groupBy('empleado_id')
            ->map(function ($group, $empleadoKey) {
                /** @var \Illuminate\Support\Collection<int, CuentaPorCobrar> $group */
                $first = $group->first();
                $nombre = $first?->empleado?->fullName() ?? '';
                $total = round((float) $group->sum('monto'), 2);

                return [
                    'empleado_id' => (int) $empleadoKey,
                    'empleado' => $nombre,
                    'total_pagado' => number_format($total, 2, '.', ''),
                    'cantidad' => $group->count(),
                    'cuentas' => $group->values()->all(),
                ];
            })
            ->sortBy('empleado', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $totalPagado = round((float) $cuentas->sum('monto'), 2);

        return [
            'sucursal_id' => $sucursalId,
            'status' => CuentaPorCobrar::STATUS_PAGADO,
            'total_pagado' => number_format($totalPagado, 2, '.', ''),
            'cantidad' => $cuentas->count(),
            'empleados' => $empleados,
        ];
    }

    /**
     * @param  array{nota?: string|null}  $data
     */
    public function pagar(Negocio $negocio, User|Staff $actor, int $id, array $data = []): CuentaPorCobrar
    {
        $this->assertCanPagar($actor, $negocio);

        $cuenta = $this->findForNegocio($negocio, $id);

        if ($cuenta->status === CuentaPorCobrar::STATUS_PAGADO) {
            throw new HttpException(422, 'Esta cuenta por cobrar ya está pagada.');
        }

        $cuenta->fill([
            'status' => CuentaPorCobrar::STATUS_PAGADO,
            'fecha_pagado' => now(),
            'pagado_por' => $this->auditUserId($actor, $negocio),
            'nota' => $data['nota'] ?? $cuenta->nota,
        ]);
        $cuenta->save();

        return $cuenta->refresh()->load([
            'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname',
            'sucursal:id,negocio_id,name,type',
            'orden:id,negocio_id,sucursal_id,order_number',
        ]);
    }

    /**
     * @param  array{ids: list<int>, nota?: string|null}  $data
     * @return list<CuentaPorCobrar>
     */
    public function pagarLote(Negocio $negocio, User|Staff $actor, array $data): array
    {
        $this->assertCanPagar($actor, $negocio);

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));

        if ($ids === []) {
            throw new HttpException(422, 'Debes enviar al menos un id para pagar.');
        }

        return DB::transaction(function () use ($negocio, $actor, $ids, $data) {
            $cuentas = $negocio->cuentasPorCobrar()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->get();

            if ($cuentas->count() !== count($ids)) {
                throw new HttpException(422, 'Una o más cuentas no pertenecen a tu negocio.');
            }

            $yaPagadas = $cuentas->where('status', CuentaPorCobrar::STATUS_PAGADO);
            if ($yaPagadas->isNotEmpty()) {
                throw new HttpException(
                    422,
                    'Hay cuentas ya pagadas en el lote: '.$yaPagadas->pluck('id')->implode(', '),
                );
            }

            $now = now();
            $nota = $data['nota'] ?? null;
            $pagadoPor = $this->auditUserId($actor, $negocio);

            foreach ($cuentas as $cuenta) {
                $cuenta->fill([
                    'status' => CuentaPorCobrar::STATUS_PAGADO,
                    'fecha_pagado' => $now,
                    'pagado_por' => $pagadoPor,
                    'nota' => $nota ?? $cuenta->nota,
                ]);
                $cuenta->save();
            }

            return $negocio->cuentasPorCobrar()
                ->with([
                    'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname',
                    'sucursal:id,negocio_id,name,type',
                    'orden:id,negocio_id,sucursal_id,order_number',
                ])
                ->whereIn('id', $ids)
                ->get()
                ->all();
        });
    }

    /**
     * Dueño siempre puede. Staff requiere permiso cuentas_por_cobrar.
     */
    private function assertCanPagar(User|Staff $actor, Negocio $negocio): void
    {
        if ($actor instanceof User) {
            if ((int) ($actor->negocio?->id ?? 0) !== (int) $negocio->id) {
                throw new HttpException(403, 'No puedes marcar cuentas de otro negocio.');
            }

            return;
        }

        $actor->loadMissing('role');

        if ((int) $actor->negocio_id !== (int) $negocio->id) {
            throw new HttpException(403, 'No puedes marcar cuentas de otro negocio.');
        }

        if (! $actor->role?->allows('cuentas_por_cobrar')) {
            throw new HttpException(403, 'No tienes permiso para marcar cuentas por cobrar como pagadas.');
        }
    }

    private function resolveSucursalIdForList(Negocio $negocio, User|Staff $actor, ?int $sucursalId): int
    {
        if ($actor instanceof Staff) {
            $id = (int) $actor->sucursal_id;
            if ($id < 1) {
                throw new HttpException(422, 'El usuario staff no tiene una sucursal asignada.');
            }

            return $id;
        }

        $id = (int) $sucursalId;
        if ($id < 1) {
            throw new HttpException(422, 'La sucursal es obligatoria para listar cuentas pagadas.');
        }

        $exists = Sucursal::query()
            ->where('negocio_id', $negocio->id)
            ->whereKey($id)
            ->exists();

        if (! $exists) {
            throw new HttpException(422, 'La sucursal no existe o no pertenece a tu negocio.');
        }

        return $id;
    }
}
