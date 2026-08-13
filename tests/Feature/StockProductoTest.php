<?php

namespace Tests\Feature;

use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockProductoTest extends TestCase
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
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Centro',
            'is_active' => true,
        ]);

        $categoria = $negocio->categoriaProductos()->create(['name' => 'Bebidas']);
        $producto = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Refresco',
            'price' => 25,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/stock-productos', [
            'sucursal_id' => $sucursal->id,
            'producto_id' => $producto->id,
            'stock_fisico' => 12,
            'stock_minimo' => 4,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stock.stock_fisico', '12.000')
            ->assertJsonPath('data.stock.stock_minimo', '4.000')
            ->assertJsonPath('data.stock.is_active', true)
            ->assertJsonPath('data.stock.activo', true)
            ->assertJsonPath('data.stock.producto.name', 'Refresco');

        $this->getJson('/api/stock-productos?sucursal_id='.$sucursal->id)
            ->assertOk()
            ->assertJsonPath('data.sucursal.name', 'Centro')
            ->assertJsonPath('data.stocks.0.producto.name', 'Refresco')
            ->assertJsonPath('data.stocks.0.stock_fisico', '12.000')
            ->assertJsonPath('data.stocks.0.stock_minimo', '4.000')
            ->assertJsonPath('data.stocks.0.is_active', true);

        $this->assertDatabaseHas('stock_productos', [
            'negocio_id' => $negocio->id,
            'sucursal_id' => $sucursal->id,
            'producto_id' => $producto->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }

    public function test_user_can_bulk_upsert_product_stock(): void
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

        $categoria = $negocio->categoriaProductos()->create(['name' => 'Comida']);
        $taco = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Taco',
            'price' => 18,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $quesadilla = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Quesadilla',
            'price' => 32,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/stock-productos/bulk', [
            'sucursal_id' => $sucursal->id,
            'items' => [
                ['producto_id' => $taco->id, 'stock_fisico' => 20, 'stock_minimo' => 5],
                ['productoId' => $quesadilla->id, 'stockFisico' => 8, 'stockMinimo' => 2],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('stock_productos', [
            'sucursal_id' => $sucursal->id,
            'producto_id' => $taco->id,
        ]);
        $this->assertDatabaseHas('stock_productos', [
            'sucursal_id' => $sucursal->id,
            'producto_id' => $quesadilla->id,
        ]);
    }

    public function test_list_requires_sucursal_id(): void
    {
        $user = User::factory()->create();
        $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/stock-productos')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Debes indicar sucursal_id.');
    }

    public function test_can_deactivate_product_for_sucursal(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        $sucursal = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Centro',
            'is_active' => true,
        ]);

        $categoria = $negocio->categoriaProductos()->create(['name' => 'Bebidas']);
        $producto = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Agua',
            'price' => 15,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $stockId = $this->putJson('/api/stock-productos', [
            'sucursal_id' => $sucursal->id,
            'producto_id' => $producto->id,
            'stock_fisico' => 10,
            'stock_minimo' => 2,
            'activo' => 'inactivo',
        ])
            ->assertOk()
            ->assertJsonPath('data.stock.is_active', false)
            ->assertJsonPath('data.stock.disponible', false)
            ->json('data.stock.id');

        $this->putJson("/api/stock-productos/{$stockId}/activa", [
            'activo' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.stock.is_active', true);

        $this->putJson("/api/stock-productos/{$stockId}/activa", [
            'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.stock.is_active', false);

        $this->getJson('/api/stock-productos?sucursal_id='.$sucursal->id.'&activo=1')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);

        $this->getJson('/api/stock-productos?sucursal_id='.$sucursal->id.'&activo=0')
            ->assertOk()
            ->assertJsonPath('data.stocks.0.producto.name', 'Agua')
            ->assertJsonPath('data.stocks.0.is_active', false);
    }
}
