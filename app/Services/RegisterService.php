<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegisterService
{
    /**
     * Crea usuario maestro + negocio en una sola transacción.
     *
     * @param  array{
     *     name: string,
     *     email: string,
     *     password: string,
     *     business_name: string,
     *     phone: string,
     *     needs_invoice?: bool,
     *     rfc?: string|null,
     *     legal_name?: string|null,
     *     tax_regime?: string|null,
     *     tax_zip?: string|null,
     *     cfdi_use?: string|null
     * }  $data
     * @return array{user: User, negocio: Negocio}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]);

            $negocio = $user->negocio()->create([
                'name' => $data['business_name'],
                'phone' => $data['phone'],
                'needs_invoice' => $data['needs_invoice'] ?? false,
                'rfc' => $data['rfc'] ?? null,
                'legal_name' => $data['legal_name'] ?? null,
                'tax_regime' => $data['tax_regime'] ?? null,
                'tax_zip' => $data['tax_zip'] ?? null,
                'cfdi_use' => $data['cfdi_use'] ?? null,
            ]);

            return [
                'user' => $user,
                'negocio' => $negocio,
            ];
        });
    }
}
