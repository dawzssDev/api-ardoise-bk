<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Orden;
use App\Models\OrdenPago;
use App\Models\Staff;
use App\Models\User;
use App\Services\Concerns\ResolvesNegocioFromActor;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrdenPagoService
{
    use ResolvesNegocioFromActor;

    public function __construct(
        private readonly OrdenService $ordenes,
    ) {}

    /**
     * Pagos de una orden (cobro único o combinado).
     *
     * @return array{
     *     orden: Orden,
     *     pagos: Collection<int, OrdenPago>,
     *     resumen: array{cantidad: int, total: string, por_tipo: list<array{tipo_pago: string, monto: string}>}
     * }
     */
    public function listForOrden(Negocio $negocio, User|Staff $actor, int $ordenId): array
    {
        $orden = $this->ordenes->findForNegocio($negocio, $ordenId);
        $this->assertCanViewOrden($actor, $orden);

        $pagos = $orden->pagos()->orderBy('id')->get();

        return [
            'orden' => $orden,
            'pagos' => $pagos,
            'resumen' => $this->resumen($pagos),
        ];
    }

    public function findForOrden(Negocio $negocio, User|Staff $actor, int $ordenId, int $pagoId): OrdenPago
    {
        $orden = $this->ordenes->findForNegocio($negocio, $ordenId);
        $this->assertCanViewOrden($actor, $orden);

        $pago = $orden->pagos()->whereKey($pagoId)->first();
        if (! $pago) {
            throw new HttpException(404, 'El pago no pertenece a esta orden.');
        }

        return $pago;
    }

    /**
     * @param  Collection<int, OrdenPago>  $pagos
     * @return array{cantidad: int, total: string, por_tipo: list<array{tipo_pago: string, monto: string}>}
     */
    private function resumen(Collection $pagos): array
    {
        $porTipo = $pagos
            ->groupBy('payment_type')
            ->map(fn (Collection $rows, string $tipo) => [
                'tipo_pago' => $tipo,
                'monto' => number_format(round((float) $rows->sum(fn (OrdenPago $p) => (float) $p->amount), 2), 2, '.', ''),
            ])
            ->values()
            ->all();

        $total = round((float) $pagos->sum(fn (OrdenPago $p) => (float) $p->amount), 2);

        return [
            'cantidad' => $pagos->count(),
            'total' => number_format($total, 2, '.', ''),
            'por_tipo' => $porTipo,
        ];
    }

    private function assertCanViewOrden(User|Staff $actor, Orden $orden): void
    {
        if ($actor instanceof Staff && (int) $actor->sucursal_id !== (int) $orden->sucursal_id) {
            throw new HttpException(403, 'No puedes consultar pagos de otra sucursal.');
        }
    }
}
