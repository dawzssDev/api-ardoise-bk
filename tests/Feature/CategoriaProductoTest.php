<?php

namespace Tests\Feature;

use App\Models\CategoriaProducto;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoriaProductoTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_manage_categoria_productos(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $create = $this->postJson('/api/categoria-productos', [
            'name' => 'Bebidas',
        ]);

        $create->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.categoria.name', 'Bebidas')
            ->assertJsonPath('data.categoria.status', CategoriaProducto::STATUS_ACTIVO);

        $id = $create->json('data.categoria.id');

        $this->putJson('/api/categoria-productos/'.$id, [
            'name' => 'Bebidas frías',
        ])
            ->assertOk()
            ->assertJsonPath('data.categoria.name', 'Bebidas frías');

        $this->getJson('/api/categoria-productos')
            ->assertOk()
            ->assertJsonPath('data.categorias.0.name', 'Bebidas frías')
            ->assertJsonPath('data.categorias.0.status', CategoriaProducto::STATUS_ACTIVO);

        $this->deleteJson('/api/categoria-productos/'.$id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.categoria.status', CategoriaProducto::STATUS_INACTIVO);

        $this->assertDatabaseHas('categoria_productos', [
            'id' => $id,
            'negocio_id' => $negocio->id,
            'status' => CategoriaProducto::STATUS_INACTIVO,
        ]);

        $this->getJson('/api/categoria-productos')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);
    }

    public function test_cannot_delete_categoria_with_active_productos(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        $categoria = $negocio->categoriaProductos()->create([
            'name' => 'Ramen',
            'status' => CategoriaProducto::STATUS_ACTIVO,
        ]);
        $producto = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Ramen rosa',
            'price' => 70,
            'status' => Producto::STATUS_ACTIVO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/categoria-productos/'.$categoria->id)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'No se puede eliminar la categoría: tienes 1 producto en esta categoría. Da de baja o reasigna ese producto primero.',
            );

        $this->assertDatabaseHas('categoria_productos', [
            'id' => $categoria->id,
            'status' => CategoriaProducto::STATUS_ACTIVO,
        ]);
        $this->assertDatabaseHas('productos', [
            'id' => $producto->id,
            'status' => Producto::STATUS_ACTIVO,
        ]);
    }

    public function test_can_delete_categoria_when_linked_productos_are_inactive(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        $categoria = $negocio->categoriaProductos()->create([
            'name' => 'Ramen',
            'status' => CategoriaProducto::STATUS_ACTIVO,
        ]);
        $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Ramen rosa',
            'price' => 70,
            'status' => Producto::STATUS_INACTIVO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/categoria-productos/'.$categoria->id)
            ->assertOk()
            ->assertJsonPath('data.categoria.status', CategoriaProducto::STATUS_INACTIVO);

        $this->assertDatabaseHas('categoria_productos', [
            'id' => $categoria->id,
            'status' => CategoriaProducto::STATUS_INACTIVO,
        ]);
    }
}
