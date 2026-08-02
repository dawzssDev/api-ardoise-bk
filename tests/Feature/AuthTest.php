<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_successfully(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Luis Pérez',
            'email' => 'luis@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'luis@example.com')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'user' => ['id', 'name', 'email', 'created_at'],
                    'token',
                    'token_type',
                ],
                'errors',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'luis@example.com',
        ]);
    }

    public function test_user_can_login_successfully(): void
    {
        $user = User::factory()->create([
            'email' => 'luis@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'luis@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'user')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonStructure([
                'data' => ['user', 'token', 'token_type'],
            ]);
    }

    public function test_staff_can_login_with_username(): void
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

        $role = $negocio->roles()->create([
            'name' => 'Cajero',
            'permissions' => Role::defaultPermissions(),
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $empleado = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'first_name' => 'Ana',
            'paternal_surname' => 'Ruiz',
            'employee_number' => 'EMP-001',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $staff = $negocio->staff()->create([
            'username' => 'ana.ruiz',
            'password' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'empleado_id' => $empleado->id,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'usuario' => 'ana.ruiz',
            'password' => 'secreto123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'staff')
            ->assertJsonPath('data.staff.id', $staff->id)
            ->assertJsonPath('data.staff.username', 'ana.ruiz')
            ->assertJsonPath('data.negocio.id', $negocio->id)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonMissingPath('data.staff.password');

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        $me = $this->withToken($token)->getJson('/api/auth/me');
        $me->assertOk()
            ->assertJsonPath('data.type', 'staff')
            ->assertJsonPath('data.staff.id', $staff->id);
    }

    public function test_inactive_staff_cannot_login(): void
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

        $role = $negocio->roles()->create([
            'name' => 'Cajero',
            'permissions' => Role::defaultPermissions(),
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $empleado = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'first_name' => 'Ana',
            'paternal_surname' => 'Ruiz',
            'employee_number' => 'EMP-002',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $negocio->staff()->create([
            'username' => 'ana.inactiva',
            'password' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'empleado_id' => $empleado->id,
            'status' => false,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->postJson('/api/auth/login', [
            'usuario' => 'ana.inactiva',
            'password' => 'secreto123',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Credenciales inválidas');
    }

    public function test_login_with_invalid_credentials_returns_401(): void
    {
        User::factory()->create([
            'email' => 'luis@example.com',
            'password' => 'password123',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'luis@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Credenciales inválidas');
    }

    public function test_me_without_token_returns_401(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_me_with_token_returns_authenticated_user(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'user')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonMissingPath('data.user.password');
    }

    public function test_logout_revokes_current_token(): void
    {
        $user = User::factory()->create();
        $plainTextToken = $user->createToken('api')->plainTextToken;

        $logout = $this->withToken($plainTextToken)->postJson('/api/auth/logout');

        $logout->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);

        // Limpia el guard cacheado entre requests del test
        $this->app['auth']->forgetGuards();

        $me = $this->withToken($plainTextToken)->getJson('/api/auth/me');

        $me->assertUnauthorized();
    }
}
