<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserService
{
    /**
     * @param  array{
     *     name?: string,
     *     email?: string,
     *     password?: string,
     *     current_password?: string
     * }  $data
     */
    public function update(User $user, array $data): User
    {
        if (isset($data['password'])) {
            if (! Hash::check((string) ($data['current_password'] ?? ''), $user->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['La contraseña actual no es correcta.'],
                ]);
            }

            $user->password = $data['password'];
        }

        if (array_key_exists('name', $data)) {
            $user->name = $data['name'];
        }

        if (array_key_exists('email', $data)) {
            $user->email = $data['email'];
        }

        $user->save();

        return $user->refresh()->load('negocio');
    }
}
