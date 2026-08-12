<?php

namespace App\Services;

use App\Models\CuentaPorCobrar;
use App\Models\Negocio;
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
     * @param  array{nota?: string|null}  $data
     */
    public function pagar(Negocio $negocio, User $actor, int $id, array $data = []): CuentaPorCobrar
    {
        $cuenta = $this->findForNegocio($negocio, $id);

        if ($cuenta->status === CuentaPorCobrar::STATUS_PAGADO) {
            throw new HttpException(422, 'Esta cuenta por cobrar ya está pagada.');
        }

        $cuenta->fill([
            'status' => CuentaPorCobrar::STATUS_PAGADO,
            'fecha_pagado' => now(),
            'pagado_por' => $actor->id,
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
    public function pagarLote(Negocio $negocio, User $actor, array $data): array
    {
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

            foreach ($cuentas as $cuenta) {
                $cuenta->fill([
                    'status' => CuentaPorCobrar::STATUS_PAGADO,
                    'fecha_pagado' => $now,
                    'pagado_por' => $actor->id,
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
}
