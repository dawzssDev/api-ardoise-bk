<?php

namespace Tests\Feature;

use App\Models\Sucursal;
use App\Models\User;
use App\Services\PlanLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_basico_plan_blocks_third_sucursal_but_vip_has_no_limit(): void
    {
        $user = User::factory()->create([
            'user_ardo_vip' => 0,
            ...app(PlanLimitService::class)->limitsForTier('basico'),
        ]);
        $negocio = $user->negocio()->create([
            'name' => 'Negocio límites',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/sucursales', [
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Uno',
        ])->assertCreated();

        $this->postJson('/api/sucursales', [
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Dos',
        ])->assertCreated();

        $this->postJson('/api/sucursales', [
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Tres',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $user->forceFill(['user_ardo_vip' => 1])->save();

        $this->postJson('/api/sucursales', [
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'VIP libre',
        ])->assertCreated();
    }

    public function test_cannot_create_categoria_producto_when_product_limit_reached(): void
    {
        $user = User::factory()->create([
            'user_ardo_vip' => 0,
            'limit_productos' => 1,
            'limit_sucursales' => 2,
            'limit_insumos' => 150,
            'limit_stock_insumos' => 150,
            'limit_proveedores' => 30,
            'limit_stock_productos' => 100,
            'limit_personal' => 25,
            'limit_cuentas_contables' => 2,
            'limit_roles' => 5,
            'limit_staff' => 8,
        ]);
        $negocio = $user->negocio()->create([
            'name' => 'Negocio cat',
            'phone' => '6670000002',
            'needs_invoice' => false,
        ]);
        $categoria = $negocio->categoriaProductos()->create([
            'name' => 'Inicial',
            'status' => 1,
        ]);
        $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Producto único',
            'price' => 10,
            'status' => 1,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/categoria-productos', [
            'name' => 'Nueva categoría',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_plus_limits_allow_four_sucursales(): void
    {
        $user = User::factory()->create([
            'user_ardo_vip' => 0,
            ...app(PlanLimitService::class)->limitsForTier('plus'),
        ]);
        $user->negocio()->create([
            'name' => 'Negocio Plus',
            'phone' => '6670000001',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        foreach (['A', 'B', 'C', 'D'] as $name) {
            $this->postJson('/api/sucursales', [
                'type' => Sucursal::TYPE_SUCURSAL,
                'name' => "Sucursal {$name}",
            ])->assertCreated();
        }

        $this->postJson('/api/sucursales', [
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Quinta',
        ])->assertStatus(422);
    }
}
