<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NegocioTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_negocio_defaults_comision_venta_tarjeta_to_three(): void
    {
        $user = User::factory()->create();
        $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/negocio')
            ->assertOk()
            ->assertJsonPath('data.negocio.comision_venta_tarjeta', 3)
            ->assertJsonPath('data.negocio.comisionVentaTarjeta', 3)
            ->assertJsonPath('data.negocio.comicionVentaTarjeta', 3);

        $this->assertDatabaseHas('negocios', [
            'user_id' => $user->id,
            'comision_venta_tarjeta' => 3,
        ]);
    }

    public function test_master_can_update_comicion_venta_tarjeta(): void
    {
        $user = User::factory()->create();
        $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/negocio', [
            'comicionVentaTarjeta' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('data.negocio.comision_venta_tarjeta', 10)
            ->assertJsonPath('data.negocio.comicionVentaTarjeta', 10);

        $this->assertDatabaseHas('negocios', [
            'user_id' => $user->id,
            'comision_venta_tarjeta' => 10,
        ]);
    }

    public function test_comision_venta_tarjeta_rejects_out_of_range(): void
    {
        $user = User::factory()->create();
        $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/negocio', [
            'comisionVentaTarjeta' => 101,
        ])->assertStatus(422);

        $this->putJson('/api/negocio', [
            'comision_venta_tarjeta' => -1,
        ])->assertStatus(422);

        $this->assertDatabaseHas('negocios', [
            'user_id' => $user->id,
            'comision_venta_tarjeta' => 3,
        ]);
    }
}
