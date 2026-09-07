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

    public function test_user_can_create_sucursal_with_monto_maximo_efectivo(): void
    {
        $user = User::factory()->create();
        $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/sucursales', [
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Centro',
            'montoMaximoEfectivo' => 5000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.sucursal.monto_maximo_efectivo', '5000.00')
            ->assertJsonPath('data.sucursal.montoMaximoEfectivo', '5000.00');

        $this->assertDatabaseHas('sucursales', [
            'name' => 'Centro',
            'monto_maximo_efectivo' => 5000.00,
        ]);
    }

    public function test_user_can_update_monto_maximo_efectivo(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);
        $sucursal = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Norte',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/sucursales/'.$sucursal->id, [
            'maximo_efectivo' => 3500.50,
        ])
            ->assertOk()
            ->assertJsonPath('data.sucursal.monto_maximo_efectivo', '3500.50');

        $this->assertDatabaseHas('sucursales', [
            'id' => $sucursal->id,
            'monto_maximo_efectivo' => 3500.50,
        ]);
    }

    public function test_monto_maximo_efectivo_rejects_negative(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);
        $sucursal = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Sur',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/sucursales/'.$sucursal->id, [
            'monto_maximo_efectivo' => -10,
        ])->assertStatus(422);

        $this->assertDatabaseHas('sucursales', [
            'id' => $sucursal->id,
            'monto_maximo_efectivo' => null,
        ]);
    }
}
