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
            ->assertJsonPath('data.role.status', true);

        $this->assertDatabaseHas('roles', [
            'negocio_id' => $negocio->id,
            'name' => 'Administrador',
            'created_by' => $user->id,
        ]);
    }
}
