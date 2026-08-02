<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_staff_user(): void
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

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/staff', [
            'usuario' => 'ana.ruiz',
            'contrasena' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'empleadoResponsable' => $empleado->id,
            'status' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.staff.username', 'ana.ruiz')
            ->assertJsonPath('data.staff.empleado_id', $empleado->id)
            ->assertJsonPath('data.staff.role.name', 'Cajero')
            ->assertJsonMissingPath('data.staff.password');

        $this->assertDatabaseHas('staff', [
            'negocio_id' => $negocio->id,
            'username' => 'ana.ruiz',
            'empleado_id' => $empleado->id,
            'created_by' => $user->id,
        ]);
    }
}
