<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Orden;
use App\Models\Staff;
use App\Models\TurnoCaja;
use App\Models\User;
use App\Models\Venta;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TurnoCajaService
{
    use ResolvesNegocioFromActor;

    /**
     * @param  array{sucursal_id?: int|null, fondo_inicial?: float|int|string|null}  $data
     */
    public function abrir(Negocio $negocio, User|Staff $actor, array $data): TurnoCaja
    {
        $sucursalId = $this->resolveSucursalId($negocio, $actor, $data['sucursal_id'] ?? null);
        $this->assertSucursalBelongs($negocio, $sucursalId);

        if ($this->openTurnoForActor($negocio, $actor, $sucursalId)) {
            throw new HttpException(422, 'Ya tienes un turno de caja abierto en esta sucursal. Ciérralo antes de abrir otro.');
        }

        $this->assertNoPendingAdminClose($negocio, $sucursalId);

        $fondo = round((float) ($data['fondo_inicial'] ?? 0), 2);
        if ($fondo < 0) {
            throw new HttpException(422, 'El fondo inicial no puede ser negativo.');
        }

        return TurnoCaja::query()->create([
            'id_user' => $actor instanceof Staff ? $actor->id : null,
            'user_id' => $actor instanceof User ? $actor->id : $this->auditUserId($actor, $negocio),
            'negocio_id' => $negocio->id,
            'sucursal_id' => $sucursalId,
            'fondo_inicial' => $fondo,
            'total_pagos_proveedores' => 0,
            'total_gastos_operativos' => 0,
            'status' => TurnoCaja::STATUS_ABIERTO,
            'status_administrador' => TurnoCaja::STATUS_ABIERTO,
            'fecha_apertura' => now(),
        ])->load($this->turnoRelations());
    }

    public function cerrar(
        TurnoCaja $turno,
        User|Staff $actor,
        float $efectivoReal,
        ?string $observaciones = null,
        ?float $efectivoRealCajera = null,
    ): TurnoCaja {
        $this->assertCanCorteCaja($actor);
        $this->assertCanManageTurno($turno, $actor);

        if ($efectivoReal < 0) {
            throw new HttpException(422, 'El efectivo real no puede ser negativo.');
        }

        if ($efectivoRealCajera !== null && $efectivoRealCajera < 0) {
            throw new HttpException(422, 'El efectivo real de la cajera no puede ser negativo.');
        }

        if ($this->isCajeraClose($actor)) {
            return $this->cerrarPorCajera($turno, $efectivoReal, $observaciones, $efectivoRealCajera);
        }

        return $this->cerrarPorAdministrador($turno, $efectivoReal, $observaciones, $efectivoRealCajera);
    }

    public function listForNegocio(
        Negocio $negocio,
        User|Staff $actor,
        int $perPage = 15,
        ?int $sucursalId = null,
        ?string $status = null,
    ): LengthAwarePaginator {
        $query = TurnoCaja::query()
            ->where('negocio_id', $negocio->id)
            ->with($this->turnoRelations())
            ->latest('id');

        if ($actor instanceof Staff && ! $this->staffHasCorteAdmin($actor)) {
            $query->where('id_user', $actor->id);
        }

        if ($sucursalId) {
            $this->assertSucursalBelongs($negocio, $sucursalId);
            $query->where('sucursal_id', $sucursalId);
        }

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $query->paginate($perPage);
    }

    /**
     * Turnos con status_administrador = abierto (pendientes de cierre admin).
     *
     * @return list<TurnoCaja>
     */
    public function listPendientesAdministrador(
        Negocio $negocio,
        User|Staff $actor,
        ?int $sucursalId = null,
    ): array {
        $query = TurnoCaja::query()
            ->where('negocio_id', $negocio->id)
            ->where('status_administrador', TurnoCaja::STATUS_ABIERTO)
            ->with($this->turnoRelations())
            ->latest('id');

        if ($sucursalId) {
            $this->assertSucursalBelongs($negocio, $sucursalId);
            $query->where('sucursal_id', $sucursalId);
        }

        // Cajera solo ve los suyos; admin/dueño ve todos los pendientes de la sucursal.
        if ($actor instanceof Staff && ! $this->staffHasCorteAdmin($actor)) {
            $query->where('id_user', $actor->id);
        }

        return $query->get()->all();
    }

    public function findForNegocio(Negocio $negocio, int $turnoId): TurnoCaja
    {
        return TurnoCaja::query()
            ->where('negocio_id', $negocio->id)
            ->with($this->turnoRelations())
            ->findOrFail($turnoId);
    }

    public function openTurnoForActor(Negocio $negocio, User|Staff $actor, ?int $sucursalId = null): ?TurnoCaja
    {
        $query = TurnoCaja::query()
            ->where('negocio_id', $negocio->id)
            ->where('status', TurnoCaja::STATUS_ABIERTO)
            ->with($this->turnoRelations())
            ->latest('id');

        if ($actor instanceof Staff) {
            $query->where('id_user', $actor->id);
        } else {
            $query->where('user_id', $actor->id)->whereNull('id_user');
        }

        if ($sucursalId) {
            $query->where('sucursal_id', $sucursalId);
        }

        return $query->first();
    }

    /**
     * Turno abierto obligatorio para cobrar (POS).
     */
    public function requireOpenTurnoForSale(
        Negocio $negocio,
        User|Staff $actor,
        int $sucursalId,
    ): TurnoCaja {
        $turno = $this->openTurnoForActor($negocio, $actor, $sucursalId);

        if (! $turno) {
            throw new HttpException(
                422,
                'Debes iniciar turno de caja antes de realizar ventas.',
            );
        }

        return $turno;
    }

    public function registerVentaFromOrden(TurnoCaja $turno, Orden $orden, User|Staff $actor): Venta
    {
        return Venta::query()->create([
            'turno_caja_id' => $turno->id,
            'id_user' => $actor instanceof Staff ? $actor->id : $turno->id_user,
            'orden_id' => $orden->id,
            'order_number' => $orden->order_number,
            'payment_type' => $orden->payment_type,
            'total' => $orden->total,
            'sucursal_id' => $orden->sucursal_id,
            'negocio_id' => $orden->negocio_id,
            'fecha_venta' => $orden->created_at ?? now(),
        ]);
    }

    public function listVentas(TurnoCaja $turno, int $perPage = 50): LengthAwarePaginator
    {
        return $turno->ventas()
            ->with(['cajera:id,username,sucursal_id', 'orden:id,order_number,customer_name,status,total'])
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * @return array{efectivo: float, tarjeta: float, transferencia: float, total: float}
     */
    public function sumVentasByPayment(TurnoCaja $turno): array
    {
        $rows = $turno->ventas()
            ->selectRaw('payment_type, SUM(total) as suma')
            ->groupBy('payment_type')
            ->pluck('suma', 'payment_type');

        $efectivo = round((float) ($rows['efectivo'] ?? 0), 2);
        $transferencia = round((float) ($rows['transferencia'] ?? 0), 2);
        // tarjeta + credito (compatibilidad con órdenes antiguas)
        $tarjeta = round(
            (float) ($rows['tarjeta'] ?? 0) + (float) ($rows['credito'] ?? 0),
            2
        );
        $total = round($efectivo + $tarjeta + $transferencia, 2);

        return [
            'efectivo' => $efectivo,
            'tarjeta' => $tarjeta,
            'transferencia' => $transferencia,
            'total' => $total,
        ];
    }

    public function previewCierre(TurnoCaja $turno): array
    {
        $totales = $this->sumVentasByPayment($turno);

        return [
            'total_ventas_efectivo' => $totales['efectivo'],
            'total_ventas_tarjeta' => $totales['tarjeta'],
            'total_ventas_transferencia' => $totales['transferencia'],
            'total_ventas' => $totales['total'],
            'total_pagos_proveedores' => (float) $turno->total_pagos_proveedores,
            'total_gastos_operativos' => (float) $turno->total_gastos_operativos,
            'efectivo_esperado' => $totales['efectivo'],
            'fondo_inicial' => (float) $turno->fondo_inicial,
            'status' => $turno->status,
            'status_administrador' => $turno->status_administrador,
        ];
    }

    /**
     * Cierre de cajera: status → cerrado; status_administrador sigue abierto.
     */
    private function cerrarPorCajera(
        TurnoCaja $turno,
        float $efectivoReal,
        ?string $observaciones,
        ?float $efectivoRealCajera,
    ): TurnoCaja {
        if (! $turno->isOpen()) {
            throw new HttpException(422, 'Este turno de caja ya está cerrado por la cajera.');
        }

        $efectivoRealCajera ??= $efectivoReal;

        return DB::transaction(function () use ($turno, $observaciones, $efectivoRealCajera) {
            $totales = $this->sumVentasByPayment($turno);

            $turno->fill([
                'total_ventas_efectivo' => $totales['efectivo'],
                'total_ventas_tarjeta' => $totales['tarjeta'],
                'total_ventas_transferencia' => $totales['transferencia'],
                'total_ventas' => $totales['total'],
                'efectivo_esperado' => $totales['efectivo'],
                'efectivo_real_cajera' => round($efectivoRealCajera, 2),
                'fecha_cierre_cajera' => now(),
                'status' => TurnoCaja::STATUS_CERRADO,
                // El administrador aún debe cerrar el corte.
                'status_administrador' => TurnoCaja::STATUS_ABIERTO,
                'observaciones_cierre' => $observaciones,
            ]);
            $turno->save();

            return $turno->refresh()->load($this->turnoRelations());
        });
    }

    /**
     * Cierre de administrador: cierra status_administrador (y status si aún estaba abierto).
     */
    private function cerrarPorAdministrador(
        TurnoCaja $turno,
        float $efectivoReal,
        ?string $observaciones,
        ?float $efectivoRealCajera,
    ): TurnoCaja {
        if (! $turno->isAdminOpen()) {
            throw new HttpException(422, 'Este corte de caja ya fue cerrado por el administrador.');
        }

        return DB::transaction(function () use ($turno, $efectivoReal, $observaciones, $efectivoRealCajera) {
            $totales = $this->sumVentasByPayment($turno);

            $efectivoEsperado = $totales['efectivo'];
            $pagosProveedores = (float) $turno->total_pagos_proveedores;
            $gastosOperativos = (float) $turno->total_gastos_operativos;

            $diferencia = round(
                $efectivoEsperado - $efectivoReal - $pagosProveedores - $gastosOperativos,
                2
            );

            $payload = [
                'total_ventas_efectivo' => $totales['efectivo'],
                'total_ventas_tarjeta' => $totales['tarjeta'],
                'total_ventas_transferencia' => $totales['transferencia'],
                'total_ventas' => $totales['total'],
                'efectivo_esperado' => $efectivoEsperado,
                'efectivo_real' => round($efectivoReal, 2),
                'diferencia' => $diferencia,
                'status' => TurnoCaja::STATUS_CERRADO,
                'status_administrador' => TurnoCaja::STATUS_CERRADO,
                'fecha_cierre' => now(),
                'observaciones_cierre' => $observaciones ?? $turno->observaciones_cierre,
            ];

            if ($efectivoRealCajera !== null) {
                $payload['efectivo_real_cajera'] = round($efectivoRealCajera, 2);
                $payload['fecha_cierre_cajera'] = $turno->fecha_cierre_cajera ?? now();
            }

            $turno->fill($payload);
            $turno->save();

            return $turno->refresh()->load($this->turnoRelations());
        });
    }

    /**
     * No se puede abrir turno si hay un corte pendiente de cierre del administrador.
     */
    private function assertNoPendingAdminClose(Negocio $negocio, int $sucursalId): void
    {
        $pendiente = TurnoCaja::query()
            ->where('negocio_id', $negocio->id)
            ->where('sucursal_id', $sucursalId)
            ->where('status_administrador', TurnoCaja::STATUS_ABIERTO)
            ->latest('id')
            ->first();

        if (! $pendiente) {
            return;
        }

        throw new HttpException(
            422,
            'Existe un corte abierto para el administrador. No se puede abrir turno hasta que se cierre ese corte.',
        );
    }

    /**
     * Dueño / corteCaja → cierre administrador.
     * Solo corteCajaCajera → cierre cajera.
     */
    private function isCajeraClose(User|Staff $actor): bool
    {
        if ($actor instanceof User) {
            return false;
        }

        return ! $this->staffHasCorteAdmin($actor)
            && (bool) $actor->role?->allows('corteCajaCajera');
    }

    private function staffHasCorteAdmin(Staff $actor): bool
    {
        $actor->loadMissing('role');

        return (bool) $actor->role?->allows('corteCaja');
    }

    /**
     * Dueño del negocio siempre puede.
     * Staff requiere corteCaja (admin) o corteCajaCajera (cajera).
     */
    private function assertCanCorteCaja(User|Staff $actor): void
    {
        if ($actor instanceof User) {
            return;
        }

        $actor->loadMissing('role');

        $canCorte = (bool) $actor->role?->allows('corteCaja')
            || (bool) $actor->role?->allows('corteCajaCajera');

        if (! $canCorte) {
            throw new HttpException(403, 'No tienes permiso para realizar el corte de caja.');
        }
    }

    private function assertCanManageTurno(TurnoCaja $turno, User|Staff $actor): void
    {
        if ($actor instanceof Staff) {
            $actor->loadMissing('role');

            // Staff con corteCaja (admin) puede cerrar cortes de su negocio.
            if ($actor->role?->allows('corteCaja')) {
                if ((int) $turno->negocio_id !== (int) $actor->negocio_id) {
                    throw new HttpException(403, 'No puedes gestionar este turno de caja.');
                }

                return;
            }

            // Cajera solo su propio turno.
            if ((int) $turno->id_user !== (int) $actor->id) {
                throw new HttpException(403, 'No puedes cerrar el turno de otra cajera.');
            }

            return;
        }

        if ((int) $turno->negocio_id !== (int) ($actor->negocio?->id)) {
            throw new HttpException(403, 'No puedes gestionar este turno de caja.');
        }
    }

    private function resolveSucursalId(Negocio $negocio, User|Staff $actor, mixed $sucursalId): int
    {
        if ($sucursalId !== null && $sucursalId !== '') {
            return (int) $sucursalId;
        }

        if ($actor instanceof Staff) {
            return (int) $actor->sucursal_id;
        }

        throw new HttpException(422, 'La sucursal es obligatoria para abrir caja.');
    }

    private function assertSucursalBelongs(Negocio $negocio, int $sucursalId): void
    {
        if (! $negocio->sucursales()->whereKey($sucursalId)->exists()) {
            throw new HttpException(422, 'La sucursal no pertenece a tu negocio.');
        }
    }

    /**
     * @return list<string>
     */
    private function turnoRelations(): array
    {
        return [
            'cajera:id,negocio_id,username,sucursal_id,empleado_id,status',
            'user:id,name,email',
            'sucursal:id,negocio_id,type,name',
        ];
    }
}
