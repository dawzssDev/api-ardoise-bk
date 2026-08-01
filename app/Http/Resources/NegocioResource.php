<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NegocioResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'needs_invoice' => $this->needs_invoice,
            'rfc' => $this->rfc,
            'legal_name' => $this->legal_name,
            'tax_regime' => $this->tax_regime,
            'tax_zip' => $this->tax_zip,
            'cfdi_use' => $this->cfdi_use,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
