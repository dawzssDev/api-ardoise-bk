<?php

namespace App\Services\Concerns;

use App\Models\Negocio;
use App\Models\Staff;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\HttpException;

trait ResolvesNegocioFromActor
{
    public function negocioForUser(User|Staff $user): Negocio
    {
        $negocio = $user->negocio;

        if (! $negocio) {
            throw new HttpException(422, 'El usuario no tiene un negocio asociado.');
        }

        return $negocio;
    }

    /**
     * created_by / updated_by apuntan a `users` (maestro).
     * Si actúa un staff, se registra el dueño del negocio.
     */
    protected function auditUserId(User|Staff $actor, ?Negocio $negocio = null): int
    {
        if ($actor instanceof User) {
            return (int) $actor->id;
        }

        $negocio ??= $actor->negocio;

        if (! $negocio?->user_id) {
            throw new HttpException(422, 'No se pudo resolver el usuario de auditoría.');
        }

        return (int) $negocio->user_id;
    }
}
