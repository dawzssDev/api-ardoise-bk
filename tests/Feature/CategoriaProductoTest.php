<?php

namespace Tests\Feature;

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
            ->assertJsonPath('data.categoria.name', 'Bebidas');

        $id = $create->json('data.categoria.id');

        $this->putJson('/api/categoria-productos/'.$id, [
            'name' => 'Bebidas frías',
        ])
            ->assertOk()
            ->assertJsonPath('data.categoria.name', 'Bebidas frías');

        $this->getJson('/api/categoria-productos')
            ->assertOk()
            ->assertJsonPath('data.categorias.0.name', 'Bebidas frías');

        $this->deleteJson('/api/categoria-productos/'.$id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('categoria_productos', [
            'id' => $id,
            'negocio_id' => $negocio->id,
        ]);
    }
}
