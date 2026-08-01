<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoriaInsumoTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_categoria_and_link_insumo(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $categoriaResponse = $this->postJson('/api/categoria-insumos', [
            'name' => 'Carnes',
        ]);

        $categoriaResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.categoria.name', 'Carnes');

        $categoriaId = $categoriaResponse->json('data.categoria.id');

        $insumoResponse = $this->postJson('/api/insumos', [
            'name' => 'Rib Eye',
            'categoria_insumo_id' => $categoriaId,
        ]);

        $insumoResponse->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.insumo.name', 'Rib Eye')
            ->assertJsonPath('data.insumo.categoria_insumo_id', $categoriaId)
            ->assertJsonPath('data.insumo.categoria.name', 'Carnes');

        $this->assertDatabaseHas('categoria_insumos', [
            'negocio_id' => $negocio->id,
            'name' => 'Carnes',
        ]);

        $this->assertDatabaseHas('insumos', [
            'negocio_id' => $negocio->id,
            'categoria_insumo_id' => $categoriaId,
            'name' => 'Rib Eye',
        ]);
    }

    public function test_cannot_delete_categoria_with_insumos(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        $categoria = $negocio->categoriaInsumos()->create(['name' => 'Verduras']);
        $negocio->insumos()->create([
            'categoria_insumo_id' => $categoria->id,
            'name' => 'Lechuga',
            'status_insumo' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/categoria-insumos/'.$categoria->id)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath(
                'message',
                'No se puede eliminar la categoría porque tiene insumos ligados.',
            );

        $this->assertDatabaseHas('categoria_insumos', ['id' => $categoria->id]);
    }

    public function test_can_delete_categoria_without_insumos(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        $categoria = $negocio->categoriaInsumos()->create(['name' => 'Sin uso']);

        Sanctum::actingAs($user);

        $this->deleteJson('/api/categoria-insumos/'.$categoria->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Categoría eliminada correctamente.');

        $this->assertDatabaseMissing('categoria_insumos', ['id' => $categoria->id]);
    }
}
