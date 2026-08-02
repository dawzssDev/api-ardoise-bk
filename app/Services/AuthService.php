<?php

namespace App\Services;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthService
{
    /**
     * Intenta autenticar primero en users (maestro) y luego en staff.
     *
     * @return array{type: 'user'|'staff', actor: User|Staff}
     */
    public function attempt(string $identifier, string $password): array
    {
        $identifier = trim($identifier);

        $user = User::query()->where('email', $identifier)->first();

        if ($user) {
            if (! Hash::check($password, $user->password)) {
                throw new HttpException(401, 'Credenciales inválidas');
            }

            return [
                'type' => 'user',
                'actor' => $user,
            ];
        }

        $staffCandidates = Staff::query()
            ->where('username', $identifier)
            ->where('status', true)
            ->get();

        foreach ($staffCandidates as $staff) {
            if (Hash::check($password, $staff->password)) {
                return [
                    'type' => 'staff',
                    'actor' => $staff,
                ];
            }
        }

        throw new HttpException(401, 'Credenciales inválidas');
    }

    public function issueToken(User|Staff $actor, string $tokenName = 'api'): string
    {
        $actor->tokens()->where('name', $tokenName)->delete();

        return $actor->createToken($tokenName)->plainTextToken;
    }
}
