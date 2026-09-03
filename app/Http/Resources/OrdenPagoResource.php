<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdenPagoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'orden_id' => $this->orden_id,
            'tipo_pago' => $this->payment_type,
            'payment_type' => $this->payment_type,
            'monto' => (string) $this->amount,
            'amount' => (string) $this->amount,
        ];
    }
}
