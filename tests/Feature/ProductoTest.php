<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductoTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_producto_with_image(): void
    {
        Storage::fake('productos');

        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);
        $categoria = $negocio->categoriaProductos()->create(['name' => 'Bebidas']);

        Sanctum::actingAs($user);

        $response = $this->post('/api/productos', [
            'name' => 'Refresco 600ml',
            'categoria_producto_id' => $categoria->id,
            'price' => 25.50,
            'image' => UploadedFile::fake()->create('refresco.jpg', 100, 'image/jpeg'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.producto.name', 'Refresco 600ml')
            ->assertJsonPath('data.producto.price', '25.50')
            ->assertJsonPath('data.producto.categoria.name', 'Bebidas');

        $imagePath = $response->json('data.producto.image');
        $imageUrl = $response->json('data.producto.image_url');
        $this->assertNotNull($imagePath);
        $this->assertNotNull($imageUrl);
        $this->assertStringContainsString('/productos/'.$negocio->id.'/', $imageUrl);
        Storage::disk('productos')->assertExists($imagePath);

        $this->get($imageUrl)->assertOk();

        $this->assertDatabaseHas('productos', [
            'negocio_id' => $negocio->id,
            'categoria_producto_id' => $categoria->id,
            'name' => 'Refresco 600ml',
            'created_by' => $user->id,
        ]);
    }
}
