<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NegocioService
{
    public function forUser(User $user): Negocio
    {
        $negocio = $user->negocio;

        if (! $negocio) {
            throw new HttpException(422, 'El usuario no tiene un negocio asociado.');
        }

        return $negocio;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Negocio $negocio, array $data): Negocio
    {
        $negocio->fill($data);
        $negocio->save();

        return $negocio->refresh();
    }
}
