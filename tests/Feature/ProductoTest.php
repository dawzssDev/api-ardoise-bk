<?php

namespace Tests\Feature;

use App\Models\CategoriaProducto;
use App\Models\Producto;
use App\Models\Sucursal;
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
        $categoria = $negocio->categoriaProductos()->create([
            'name' => 'Bebidas',
            'status' => CategoriaProducto::STATUS_ACTIVO,
        ]);

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
            ->assertJsonPath('data.producto.status', Producto::STATUS_ACTIVO)
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
            'status' => Producto::STATUS_ACTIVO,
            'created_by' => $user->id,
        ]);
    }

    public function test_delete_producto_sets_status_inactive_even_with_orden(): void
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
        $this->postJson('/api/turnos-caja/abrir', [
            'sucursal_id' => $sucursal->id,
            'fondo_inicial' => 10,
        ])->assertCreated();

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Mesa 1',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [[
                'producto_id' => $producto->id,
                'cantidad' => 1,
            ]],
        ])->assertCreated();

        $this->deleteJson('/api/productos/'.$producto->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.producto.status', Producto::STATUS_INACTIVO);

        $this->assertDatabaseHas('productos', [
            'id' => $producto->id,
            'negocio_id' => $negocio->id,
            'status' => Producto::STATUS_INACTIVO,
        ]);

        $this->getJson('/api/productos')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);
    }

    public function test_cannot_create_producto_with_inactive_categoria(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);
        $categoria = $negocio->categoriaProductos()->create([
            'name' => 'Ramen',
            'status' => CategoriaProducto::STATUS_INACTIVO,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/productos', [
            'name' => 'Ramen rosa',
            'categoria_producto_id' => $categoria->id,
            'price' => 70,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_list_returns_all_productos_and_can_filter_by_categoria(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        $esquites = $negocio->categoriaProductos()->create([
            'name' => 'Esquites',
            'status' => CategoriaProducto::STATUS_ACTIVO,
        ]);
        $especialidades = $negocio->categoriaProductos()->create([
            'name' => 'Especialidades',
            'status' => CategoriaProducto::STATUS_ACTIVO,
        ]);

        for ($i = 1; $i <= 20; $i++) {
            $negocio->productos()->create([
                'categoria_producto_id' => $esquites->id,
                'name' => "Esquite {$i}",
                'price' => 40 + $i,
                'status' => Producto::STATUS_ACTIVO,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        for ($i = 1; $i <= 5; $i++) {
            $negocio->productos()->create([
                'categoria_producto_id' => $especialidades->id,
                'name' => "Especial {$i}",
                'price' => 90 + $i,
                'status' => Producto::STATUS_ACTIVO,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);
        }

        Sanctum::actingAs($user);

        // Sin paginate=1: devuelve TODOS (aunque el front mande page/per_page).
        $this->getJson('/api/productos?page=1&per_page=15')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 25)
            ->assertJsonCount(25, 'data.productos');

        $this->getJson('/api/productos?categoria_producto_id='.$esquites->id)
            ->assertOk()
            ->assertJsonPath('data.meta.total', 20)
            ->assertJsonPath('data.meta.categoria_producto_id', $esquites->id)
            ->assertJsonCount(20, 'data.productos');

        $this->getJson('/api/productos?categoria_id='.$especialidades->id)
            ->assertOk()
            ->assertJsonPath('data.meta.total', 5)
            ->assertJsonCount(5, 'data.productos');

        $this->getJson('/api/productos?paginate=1&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 25)
            ->assertJsonPath('data.meta.per_page', 10)
            ->assertJsonPath('data.meta.last_page', 3)
            ->assertJsonCount(10, 'data.productos');
    }

    public function test_user_can_create_producto_with_descuento_stock_json(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);
        $categoria = $negocio->categoriaProductos()->create([
            'name' => 'Especialidades',
            'status' => CategoriaProducto::STATUS_ACTIVO,
        ]);
        $tostitos = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Tostitos',
            'price' => 20,
            'status' => Producto::STATUS_ACTIVO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $categoriaInsumo = $negocio->categoriaInsumos()->create(['name' => 'Granos']);
        $esquite = $negocio->insumos()->create([
            'categoria_insumo_id' => $categoriaInsumo->id,
            'name' => 'Esquite',
            'unidad_medida' => 'g',
            'status_insumo' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $crema = $negocio->insumos()->create([
            'categoria_insumo_id' => $categoriaInsumo->id,
            'name' => 'CremaEsquite',
            'unidad_medida' => 'g',
            'status_insumo' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/productos', [
            'name' => 'Tostiesquites',
            'categoria_producto_id' => $categoria->id,
            'price' => 55,
            'descuentoStockProd' => [
                ['Producto' => 'Tostitos', 'cantidad' => 1],
            ],
            'descuentoStockInsum' => [
                ['Insumo' => 'Esquite', 'cantidad' => 500],
                ['Insumo' => 'CremaEsquite', 'cantidad' => 100],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.producto.name', 'Tostiesquites')
            ->assertJsonPath('data.producto.descuentoStockProd.0.id', $tostitos->id)
            ->assertJsonPath('data.producto.descuentoStockProd.0.Producto', 'Tostitos')
            ->assertJsonPath('data.producto.descuentoStockProd.0.cantidad', 1)
            ->assertJsonPath('data.producto.descuentoStockInsum.0.id', $esquite->id)
            ->assertJsonPath('data.producto.descuentoStockInsum.0.Insumo', 'Esquite')
            ->assertJsonPath('data.producto.descuentoStockInsum.0.cantidad', 500)
            ->assertJsonPath('data.producto.descuentoStockInsum.1.id', $crema->id)
            ->assertJsonPath('data.producto.descuentoStockInsum.1.Insumo', 'CremaEsquite')
            ->assertJsonPath('data.producto.descuentoStockInsum.1.cantidad', 100);

        $this->assertDatabaseHas('productos', [
            'negocio_id' => $negocio->id,
            'name' => 'Tostiesquites',
        ]);

        $producto = Producto::query()->where('name', 'Tostiesquites')->first();
        $this->assertSame($tostitos->id, $producto->descuento_stock_prod[0]['id']);
        $this->assertSame('Tostitos', $producto->descuento_stock_prod[0]['Producto']);
        $this->assertEquals(1, $producto->descuento_stock_prod[0]['cantidad']);
        $this->assertCount(2, $producto->descuento_stock_insum);
    }

    public function test_cannot_create_producto_with_unknown_descuento_stock(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);
        $categoria = $negocio->categoriaProductos()->create([
            'name' => 'Especialidades',
            'status' => CategoriaProducto::STATUS_ACTIVO,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/productos', [
            'name' => 'Tostiesquites',
            'categoria_producto_id' => $categoria->id,
            'price' => 55,
            'descuento_stock_prod' => [
                ['Producto' => 'Tostitos', 'cantidad' => 1],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->postJson('/api/productos', [
            'name' => 'Tostiesquites',
            'categoria_producto_id' => $categoria->id,
            'price' => 55,
            'descuento_stock_insum' => [
                ['Insumo' => 'Esquite', 'cantidad' => 500],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_user_can_update_descuento_stock_by_id(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);
        $categoria = $negocio->categoriaProductos()->create([
            'name' => 'Especialidades',
            'status' => CategoriaProducto::STATUS_ACTIVO,
        ]);
        $tostitos = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Tostitos',
            'price' => 20,
            'status' => Producto::STATUS_ACTIVO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $producto = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Tostiesquites',
            'price' => 55,
            'status' => Producto::STATUS_ACTIVO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $categoriaInsumo = $negocio->categoriaInsumos()->create(['name' => 'Granos']);
        $esquite = $negocio->insumos()->create([
            'categoria_insumo_id' => $categoriaInsumo->id,
            'name' => 'Esquite',
            'unidad_medida' => 'g',
            'status_insumo' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/productos/'.$producto->id, [
            'descuento_stock_prod' => [
                ['id' => $tostitos->id, 'cantidad' => 1],
            ],
            'descuento_stock_insum' => [
                ['id' => $esquite->id, 'cantidad' => 500],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.producto.descuento_stock_prod.0.id', $tostitos->id)
            ->assertJsonPath('data.producto.descuento_stock_prod.0.Producto', 'Tostitos')
            ->assertJsonPath('data.producto.descuento_stock_insum.0.id', $esquite->id)
            ->assertJsonPath('data.producto.descuento_stock_insum.0.Insumo', 'Esquite');

        $this->putJson('/api/productos/'.$producto->id, [
            'descuento_stock_prod' => [],
            'descuento_stock_insum' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.producto.descuento_stock_prod', [])
            ->assertJsonPath('data.producto.descuento_stock_insum', null);
    }

    public function test_user_can_edit_producto_to_discount_its_own_stock(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);
        $categoria = $negocio->categoriaProductos()->create([
            'name' => 'Bebidas',
            'status' => CategoriaProducto::STATUS_ACTIVO,
        ]);

        Sanctum::actingAs($user);

        $create = $this->postJson('/api/productos', [
            'name' => 'CocaCola',
            'categoria_producto_id' => $categoria->id,
            'price' => 20,
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.producto.descuentoStockProd', null)
            ->assertJsonPath('data.producto.descuentoStockInsum', null);

        $cocaId = (int) $create->json('data.producto.id');

        $this->putJson('/api/productos/'.$cocaId, [
            'descuentoStockProd' => [
                ['id' => $cocaId, 'Producto' => 'CocaCola', 'cantidad' => 1],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.producto.descuentoStockProd.0.id', $cocaId)
            ->assertJsonPath('data.producto.descuentoStockProd.0.Producto', 'CocaCola')
            ->assertJsonPath('data.producto.descuentoStockProd.0.cantidad', 1)
            ->assertJsonPath('data.producto.descuentoStockInsum', null);
    }
}
