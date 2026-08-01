<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'negocio' => $this->whenLoaded(
                'negocio',
                fn () => (new NegocioResource($this->negocio))->resolve(),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
