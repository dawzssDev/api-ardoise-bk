<?php

namespace App\Http\Resources;

use App\Models\MaeCuentaContaSucDetalle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaeCuentaContaSucDetalleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = (int) $this->status;
        $deleted = (int) $this->deleted;

        return [
            'id' => $this->id,
            'negocio_id' => $this->negocio_id,
            'negocioID' => $this->negocio_id,
            'mae_cuenta_conta_suc_id' => $this->mae_cuenta_conta_suc_id,
            'idMaeCuentaContaSuc' => $this->mae_cuenta_conta_suc_id,
            'cuenta' => $this->whenLoaded('cuenta', fn () => $this->cuenta ? [
                'id' => $this->cuenta->id,
                'tipo_cuenta' => $this->cuenta->tipo_cuenta,
                'sucursal_id' => $this->cuenta->sucursal_id,
                'titulo_cuenta' => $this->cuenta->titulo_cuenta,
            ] : null),
            'tipo_movimiento' => $this->tipo_movimiento,
            'tipoMovimiento' => $this->tipo_movimiento,
            'tipo_solicitud' => $this->tipoSolicitudLabel(),
            'tipoSolicitud' => $this->tipoSolicitudLabel(),
            'sentido' => $this->sentido(),
            'es_salida' => $this->sentido() === 'salida',
            'esSalida' => $this->sentido() === 'salida',
            'es_entrada' => $this->sentido() === 'entrada',
            'esEntrada' => $this->sentido() === 'entrada',
            'cuenta_origen_id' => $this->cuenta_origen_id,
            'cuentaOrigen' => $this->cuenta_origen_id,
            'cuenta_origen' => $this->whenLoaded('cuentaOrigen', fn () => $this->cuentaSnippet($this->cuentaOrigen)),
            'monto_movimiento' => (string) $this->monto_movimiento,
            'montoMovimiento' => (string) $this->monto_movimiento,
            'cuenta_destino_id' => $this->cuenta_destino_id,
            'cuentaDestino' => $this->cuenta_destino_id,
            'cuenta_destino' => $this->whenLoaded('cuentaDestino', fn () => $this->cuentaSnippet($this->cuentaDestino)),
            'descripcion_movimiento' => $this->descripcion_movimiento,
            'descripcionMovimiento' => $this->descripcion_movimiento,
            'status' => $status,
            'status_label' => MaeCuentaContaSucDetalle::labelForStatus($status),
            'deleted' => $deleted,
            'delete' => $deleted,
            'userCreation' => $this->created_by,
            'userUpdate' => $this->updated_by,
            'created_by' => $this->whenLoaded('createdBy', fn () => [
                'id' => $this->createdBy?->id,
                'name' => $this->createdBy?->name,
                'email' => $this->createdBy?->email,
            ]),
            'updated_by' => $this->whenLoaded('updatedBy', fn () => $this->updatedBy ? [
                'id' => $this->updatedBy->id,
                'name' => $this->updatedBy->name,
                'email' => $this->updatedBy->email,
            ] : null),
            'dateCreation' => $this->created_at?->toIso8601String(),
            'dateUpdate' => $this->updated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{id: int, tipo_cuenta: string, sucursal_id: int|null, titulo_cuenta: string, saldo: string}|null
     */
    private function cuentaSnippet(mixed $cuenta): ?array
    {
        if (! $cuenta) {
            return null;
        }

        return [
            'id' => $cuenta->id,
            'tipo_cuenta' => $cuenta->tipo_cuenta,
            'sucursal_id' => $cuenta->sucursal_id,
            'titulo_cuenta' => $cuenta->titulo_cuenta,
            'saldo' => (string) $cuenta->saldo,
        ];
    }

    private function tipoSolicitudLabel(): string
    {
        return match ($this->tipo_movimiento) {
            MaeCuentaContaSucDetalle::TIPO_RETIRO => 'retiro',
            MaeCuentaContaSucDetalle::TIPO_TRANSFERENCIA => 'transferencia',
            MaeCuentaContaSucDetalle::TIPO_VENTA_EFECTIVO => 'venta_efectivo',
            MaeCuentaContaSucDetalle::TIPO_VENTA_TARJETA => 'venta_tarjeta',
            MaeCuentaContaSucDetalle::TIPO_GASTO,
            MaeCuentaContaSucDetalle::TIPO_GASTO_OPERATIVO,
            MaeCuentaContaSucDetalle::TIPO_PAGO_PROVEEDOR,
            MaeCuentaContaSucDetalle::TIPO_RETIRO_EFECTIVO => 'gasto',
            default => 'deposito',
        };
    }

    /**
     * Dirección del movimiento respecto a la cuenta del detalle.
     * gasto / retiro / origen de un traspaso → salida.
     * deposito / venta / destino de un traspaso → entrada.
     */
    private function sentido(): string
    {
        if (MaeCuentaContaSucDetalle::isTipoGasto((string) $this->tipo_movimiento)) {
            return 'salida';
        }

        $cuentaId = (int) $this->mae_cuenta_conta_suc_id;
        $origenId = (int) $this->cuenta_origen_id;
        $destinoId = (int) $this->cuenta_destino_id;

        if ($origenId > 0 && $origenId === $cuentaId && $origenId !== $destinoId) {
            return 'salida';
        }

        return 'entrada';
    }
}
