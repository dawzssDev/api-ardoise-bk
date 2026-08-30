<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Orden;
use App\Models\Staff;
use App\Models\User;
use Carbon\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrdenPorFechaService
{
    public function __construct(
        private readonly OrdenService $ordenes,
    ) {}

    /**
     * Órdenes de un día de la sucursal (todas + buckets Nuevo / En preparación / Listo).
     * Si $fecha es null, usa el día actual (timezone de la app).
     *
     * @return array{
     *     fecha: string,
     *     sucursal: array{id: int, type: string, name: string},
     *     ordenes: list<Orden>,
     *     nuevo: list<Orden>,
     *     en_preparacion: list<Orden>,
     *     listo: list<Orden>
     * }
     */
    public function list(
        Negocio $negocio,
        User|Staff $actor,
        ?string $fecha = null,
        ?int $sucursalId = null,
    ): array {
        $fechaDia = $this->resolveFecha($fecha);

        $resolvedSucursalId = $this->ordenes->resolveSucursalForQuery(
            $negocio,
            $actor,
            $sucursalId,
            requireForMaestro: true,
        );

        /** @var \App\Models\Sucursal $sucursal */
        $sucursal = $negocio->sucursales()->whereKey($resolvedSucursalId)->firstOrFail();

        $ordenes = $negocio->ordenes()
            ->with($this->ordenes->ordenRelations())
            ->where('sucursal_id', $resolvedSucursalId)
            ->whereDate('created_at', $fechaDia)
            ->latest('id')
            ->get();

        $nuevo = [];
        $enPreparacion = [];
        $listo = [];

        foreach ($ordenes as $orden) {
            $bucket = $this->ordenes->kitchenBucketForOrden($orden);
            if ($bucket === 'nuevo') {
                $nuevo[] = $orden;
            } elseif ($bucket === 'en_preparacion') {
                $enPreparacion[] = $orden;
            } elseif ($bucket === 'listo') {
                $listo[] = $orden;
            }
        }

        return [
            'fecha' => $fechaDia,
            'sucursal' => [
                'id' => $sucursal->id,
                'type' => $sucursal->type,
                'name' => $sucursal->name,
            ],
            'ordenes' => $ordenes->all(),
            'nuevo' => $nuevo,
            'en_preparacion' => $enPreparacion,
            'listo' => $listo,
        ];
    }

    private function resolveFecha(?string $fecha): string
    {
        if ($fecha === null || trim($fecha) === '') {
            return now()->toDateString();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($fecha))->toDateString();
        } catch (\Throwable) {
            throw new HttpException(422, 'La fecha debe tener el formato YYYY-MM-DD.');
        }
    }
}
