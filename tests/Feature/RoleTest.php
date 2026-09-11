<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_role_with_permissions_map(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $permissions = Role::defaultPermissions();
        $permissions['pos'] = true;
        $permissions['users'] = true;
        $permissions['nuevoPedido'] = true;
        $permissions['enPreparacionPedido'] = true;

        $response = $this->postJson('/api/roles', [
            'name' => 'Administrador',
            'permissions' => $permissions,
            'status' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role.name', 'Administrador')
            ->assertJsonPath('data.role.permissions.pos', true)
            ->assertJsonPath('data.role.permissions.users', true)
            ->assertJsonPath('data.role.permissions.kitchen', false)
            ->assertJsonPath('data.role.permissions.nuevoPedido', true)
            ->assertJsonPath('data.role.permissions.enPreparacionPedido', true)
            ->assertJsonPath('data.role.permissions.pedidosListos', false)
            ->assertJsonPath('data.role.permissions.corteCaja', false)
            ->assertJsonPath('data.role.permissions.corteCajaGerenteAdmo', false)
            ->assertJsonPath('data.role.permissions.stock_products', false)
            ->assertJsonPath('data.role.permissions.ventaDirecta', false)
            ->assertJsonPath('data.role.permissions.levantarOrden', false)
            ->assertJsonPath('data.role.permissions.ordenEnMesa', false)
            ->assertJsonPath('data.role.permissions.mesas', false)
            ->assertJsonPath('data.role.permissions.cuentasContables', false)
            ->assertJsonPath('data.role.status', true);

        $this->assertDatabaseHas('roles', [
            'negocio_id' => $negocio->id,
            'name' => 'Administrador',
            'created_by' => $user->id,
        ]);
    }

    public function test_user_can_update_kitchen_column_permissions(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        $role = $negocio->roles()->create([
            'name' => 'Cocina',
            'permissions' => Role::defaultPermissions(),
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $permissions = Role::defaultPermissions();
        $permissions['kitchen'] = true;
        $permissions['nuevoPedido'] = true;
        $permissions['enPreparacionPedido'] = true;
        $permissions['pedidosListos'] = false;
        $permissions['corteCaja'] = true;
        $permissions['corteCajaGerenteAdmo'] = true;
        $permissions['stock_products'] = true;
        $permissions['ventaDirecta'] = true;
        $permissions['levantarOrden'] = true;
        $permissions['ordenEnMesa'] = true;
        $permissions['mesas'] = true;
        $permissions['cuentasContables'] = true;

        $response = $this->putJson("/api/roles/{$role->id}", [
            'permissions' => $permissions,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.role.permissions.nuevoPedido', true)
            ->assertJsonPath('data.role.permissions.enPreparacionPedido', true)
            ->assertJsonPath('data.role.permissions.pedidosListos', false)
            ->assertJsonPath('data.role.permissions.corteCaja', true)
            ->assertJsonPath('data.role.permissions.corteCajaGerenteAdmo', true)
            ->assertJsonPath('data.role.permissions.stock_products', true)
            ->assertJsonPath('data.role.permissions.ventaDirecta', true)
            ->assertJsonPath('data.role.permissions.levantarOrden', true)
            ->assertJsonPath('data.role.permissions.ordenEnMesa', true)
            ->assertJsonPath('data.role.permissions.mesas', true)
            ->assertJsonPath('data.role.permissions.cuentasContables', true);
    }

    public function test_update_accepts_kitchen_column_aliases(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        $role = $negocio->roles()->create([
            'name' => 'Cocina',
            'permissions' => Role::defaultPermissions(),
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $permissions = Role::defaultPermissions();
        $permissions['kitchen'] = true;
        unset(
            $permissions['nuevoPedido'],
            $permissions['enPreparacionPedido'],
            $permissions['pedidosListos'],
        );
        $permissions['nuevo'] = true;
        $permissions['enPreparacion'] = true;
        $permissions['listo'] = false;
        unset($permissions['stock_products']);
        $permissions['STOCK_PRODUCTS'] = true;

        $response = $this->putJson("/api/roles/{$role->id}", [
            'permissions' => $permissions,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.role.permissions.nuevoPedido', true)
            ->assertJsonPath('data.role.permissions.enPreparacionPedido', true)
            ->assertJsonPath('data.role.permissions.pedidosListos', false)
            ->assertJsonPath('data.role.permissions.stock_products', true);
    }

    public function test_user_can_set_corte_caja_gerente_admo_permission(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $permissions = Role::defaultPermissions();
        $this->assertArrayHasKey('corteCajaGerenteAdmo', $permissions);
        $this->assertFalse($permissions['corteCajaGerenteAdmo']);

        $permissions['corteCajaGerenteAdmo'] = true;

        $roleId = $this->postJson('/api/roles', [
            'name' => 'Gerente',
            'permissions' => $permissions,
        ])
            ->assertCreated()
            ->assertJsonPath('data.role.permissions.corteCajaGerenteAdmo', true)
            ->json('data.role.id');

        $permissions['corteCajaGerenteAdmo'] = false;
        unset($permissions['corteCajaGerenteAdmo']);
        $permissions['corte_caja_gerente_admo'] = true;

        $this->putJson("/api/roles/{$roleId}", [
            'permissions' => $permissions,
        ])
            ->assertOk()
            ->assertJsonPath('data.role.permissions.corteCajaGerenteAdmo', true);
    }

    public function test_user_can_set_orden_en_mesa_permission(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $permissions = Role::defaultPermissions();
        $this->assertArrayHasKey('ordenEnMesa', $permissions);
        $this->assertFalse($permissions['ordenEnMesa']);

        $permissions['ordenEnMesa'] = true;

        $roleId = $this->postJson('/api/roles', [
            'name' => 'Mesero',
            'permissions' => $permissions,
        ])
            ->assertCreated()
            ->assertJsonPath('data.role.permissions.ordenEnMesa', true)
            ->json('data.role.id');

        $permissions['ordenEnMesa'] = false;
        unset($permissions['ordenEnMesa']);
        $permissions['orden_en_mesa'] = true;

        $this->putJson("/api/roles/{$roleId}", [
            'permissions' => $permissions,
        ])
            ->assertOk()
            ->assertJsonPath('data.role.permissions.ordenEnMesa', true);
    }

    public function test_user_can_set_mesas_permission(): void
    {
        $user = User::factory()->create();
        $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $permissions = Role::defaultPermissions();
        $this->assertArrayHasKey('mesas', $permissions);
        $this->assertFalse($permissions['mesas']);

        $permissions['mesas'] = true;

        $roleId = $this->postJson('/api/roles', [
            'name' => 'Capitan de mesas',
            'permissions' => $permissions,
        ])
            ->assertCreated()
            ->assertJsonPath('data.role.permissions.mesas', true)
            ->json('data.role.id');

        $permissions['mesas'] = false;
        unset($permissions['mesas']);
        $permissions['mesa'] = true;

        $this->putJson("/api/roles/{$roleId}", [
            'permissions' => $permissions,
        ])
            ->assertOk()
            ->assertJsonPath('data.role.permissions.mesas', true);
    }
}
