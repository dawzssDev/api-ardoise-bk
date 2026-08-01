<?php

namespace Tests\Feature;

use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SucursalTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_sucursal_name(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        $sucursal = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Sucursal Original',
            'is_active' => true,
            'street' => 'Av. Juárez 123',
            'neighborhood' => 'Centro Histórico',
            'city' => 'Monterrey',
            'state' => 'N.L.',
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/sucursales/'.$sucursal->id, [
            'type' => 'sucursal',
            'name' => 'MERCADO DE ABASTOS',
            'is_active' => true,
            'street' => 'Av. Juárez 123',
            'neighborhood' => 'Centro Histórico',
            'city' => 'Monterrey',
            'state' => 'N.L.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sucursal.name', 'MERCADO DE ABASTOS');

        $this->assertDatabaseHas('sucursales', [
            'id' => $sucursal->id,
            'name' => 'MERCADO DE ABASTOS',
        ]);
    }
}
