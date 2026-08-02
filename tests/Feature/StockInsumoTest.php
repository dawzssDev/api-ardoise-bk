<?php

namespace Tests\Feature;

use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockInsumoTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upsert_and_list_stock_by_sucursal(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        $sucursal = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_BODEGA,
            'name' => 'BODEGA ALTURAS',
            'is_active' => true,
        ]);

        $categoria = $negocio->categoriaInsumos()->create(['name' => 'Dulces']);
        $insumo = $negocio->insumos()->create([
            'categoria_insumo_id' => $categoria->id,
            'name' => 'Gomitas',
            'status_insumo' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/stock-insumos', [
            'sucursal_id' => $sucursal->id,
            'insumo_id' => $insumo->id,
            'stock_fisico' => 5,
            'stock_minimo' => 4,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stock.stock_fisico', '5.000')
            ->assertJsonPath('data.stock.stock_minimo', '4.000');

        $this->getJson('/api/stock-insumos?sucursal_id='.$sucursal->id)
            ->assertOk()
            ->assertJsonPath('data.sucursal.name', 'BODEGA ALTURAS')
            ->assertJsonPath('data.stocks.0.insumo.name', 'Gomitas')
            ->assertJsonPath('data.stocks.0.stock_fisico', '5.000')
            ->assertJsonPath('data.stocks.0.stock_minimo', '4.000');

        $this->assertDatabaseHas('stock_insumos', [
            'negocio_id' => $negocio->id,
            'sucursal_id' => $sucursal->id,
            'insumo_id' => $insumo->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
