<?php

namespace Tests\Feature;

use App\Models\TipoVenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TipoVentaTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_manage_tipos_venta(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $create = $this->postJson('/api/tipos-venta', [
            'name' => 'Policía',
            'tipo_descuento' => TipoVenta::TIPO_PORCENTAJE,
            'valor_descuento' => 20,
        ]);

        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tipo_venta.name', 'Policía')
            ->assertJsonPath('data.tipo_venta.tipo_descuento', 'porcentaje')
            ->assertJsonPath('data.tipo_venta.valor_descuento', '20.00')
            ->assertJsonPath('data.tipo_venta.status', true);

        $id = $create->json('data.tipo_venta.id');

        $this->putJson('/api/tipos-venta/'.$id, [
            'name' => 'Policía -20%',
            'tipo_descuento' => TipoVenta::TIPO_PORCENTAJE,
            'valor_descuento' => 25,
        ])
            ->assertOk()
            ->assertJsonPath('data.tipo_venta.name', 'Policía -20%')
            ->assertJsonPath('data.tipo_venta.valor_descuento', '25.00');

        $this->getJson('/api/tipos-venta')
            ->assertOk()
            ->assertJsonPath('data.tipos_venta.0.name', 'Policía -20%');

        $this->deleteJson('/api/tipos-venta/'.$id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('tb_tipos_venta', [
            'id' => $id,
            'negocio_id' => $negocio->id,
        ]);
    }

    public function test_gratis_and_ninguno_clear_valor_descuento(): void
    {
        $user = User::factory()->create();
        $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000001',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/tipos-venta', [
            'name' => 'Cortesía',
            'tipo_descuento' => TipoVenta::TIPO_GRATIS,
            'valor_descuento' => 50,
        ])
            ->assertCreated()
            ->assertJsonPath('data.tipo_venta.tipo_descuento', 'gratis')
            ->assertJsonPath('data.tipo_venta.valor_descuento', null);

        $this->postJson('/api/tipos-venta', [
            'name' => 'Público general',
            'tipo_descuento' => TipoVenta::TIPO_NINGUNO,
        ])
            ->assertCreated()
            ->assertJsonPath('data.tipo_venta.tipo_descuento', 'ninguno')
            ->assertJsonPath('data.tipo_venta.valor_descuento', null);
    }
}
