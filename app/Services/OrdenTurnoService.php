<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Orden;
use App\Models\Staff;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrdenTurnoService
{
    public function __construct(
        private readonly OrdenService $ordenes,
        private readonly TurnoCajaService $turnosCaja,
    ) {}

    /**
     * Órdenes de un turno de caja, agrupadas por cobro:
     * pendientes por pagar, pagadas y canceladas.
     *
     * Sin columna extra: el turno se infiere por sucursal + ventana
     * [fecha_apertura, fecha_cierre] (o ahora si sigue abierto).
     *
     * @return array{
     *     turno: TurnoCaja,
     *     sucursal: array{id: int, type: string, name: string},
     *     ordenes: list<Orden>,
     *     pendientes_por_pagar: list<Orden>,
     *     pagadas: list<Orden>,
     *     canceladas: list<Orden>,
     *     resumen: array{pendientes_por_pagar: int, pagadas: int, canceladas: int, total: int}
     * }
     */
    public function list(
        Negocio $negocio,
        User|Staff $actor,
        ?int $sucursalId = null,
        ?int $turnoId = null,
    ): array {
        $turno = $this->resolveTurno($negocio, $actor, $sucursalId, $turnoId);

        /** @var Sucursal $sucursal */
        $sucursal = $negocio->sucursales()->whereKey($turno->sucursal_id)->firstOrFail();

        $desde = $turno->fecha_apertura ?? $turno->created_at;
        $hasta = $turno->fecha_cierre ?? now();

        $ordenes = $negocio->ordenes()
            ->with($this->ordenes->ordenRelations())
            ->where('sucursal_id', $turno->sucursal_id)
            ->where('created_at', '>=', $desde)
            ->where('created_at', '<=', $hasta)
            ->latest('id')
            ->get();

        $pendientes = [];
        $pagadas = [];
        $canceladas = [];

        foreach ($ordenes as $orden) {
            if ((int) $orden->status === Orden::STATUS_CANCELADA) {
                $canceladas[] = $orden;
            } elseif ($orden->isPendientePago()) {
                $pendientes[] = $orden;
            } else {
                $pagadas[] = $orden;
            }
        }

        $all = $ordenes->all();

        return [
            'turno' => $turno,
            'sucursal' => [
                'id' => $sucursal->id,
                'type' => $sucursal->type,
                'name' => $sucursal->name,
            ],
            'ordenes' => $all,
            'pendientes_por_pagar' => $pendientes,
            'pagadas' => $pagadas,
            'canceladas' => $canceladas,
            'resumen' => [
                'pendientes_por_pagar' => count($pendientes),
                'pagadas' => count($pagadas),
                'canceladas' => count($canceladas),
                'total' => count($all),
            ],
        ];
    }

    private function resolveTurno(
        Negocio $negocio,
        User|Staff $actor,
        ?int $sucursalId,
        ?int $turnoId,
    ): TurnoCaja {
        if ($turnoId !== null && $turnoId > 0) {
            $turno = $this->turnosCaja->findForNegocio($negocio, $turnoId);
            $this->assertCanViewTurno($actor, $turno);

            return $turno;
        }

        if ($actor instanceof Staff) {
            $staffSucursalId = (int) $actor->sucursal_id;
            if ($staffSucursalId <= 0) {
                throw new HttpException(422, 'Tu usuario staff no tiene sucursal asignada.');
            }

            if ($sucursalId !== null && $sucursalId !== $staffSucursalId) {
                throw new HttpException(403, 'No puedes consultar pedidos de otra sucursal.');
            }

            $turno = $this->turnosCaja->openTurnoForSucursal($negocio, $staffSucursalId);
            if (! $turno) {
                throw new HttpException(422, 'No hay un turno de caja abierto en esta sucursal.');
            }

            return $turno;
        }

        if ($sucursalId !== null && $sucursalId > 0) {
            if (! $negocio->sucursales()->whereKey($sucursalId)->exists()) {
                throw new HttpException(422, 'La sucursal no pertenece a tu negocio.');
            }

            $turno = $this->turnosCaja->openTurnoForSucursal($negocio, $sucursalId);
            if (! $turno) {
                throw new HttpException(422, 'No hay un turno de caja abierto en esta sucursal.');
            }

            return $turno;
        }

        $turno = $this->turnosCaja->openTurnoForActor($negocio, $actor, null);
        if (! $turno) {
            throw new HttpException(
                422,
                'Selecciona una sucursal o indica el turno para ver las órdenes.',
            );
        }

        return $turno;
    }

    private function assertCanViewTurno(User|Staff $actor, TurnoCaja $turno): void
    {
        if ($actor instanceof Staff && (int) $actor->sucursal_id !== (int) $turno->sucursal_id) {
            throw new HttpException(403, 'No puedes consultar órdenes de otra sucursal.');
        }
    }
}
