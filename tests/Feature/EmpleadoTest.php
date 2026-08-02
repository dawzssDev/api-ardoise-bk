<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmpleadoTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_empleado_with_salary_and_image(): void
    {
        Storage::fake('empleados');

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

        Sanctum::actingAs($user);

        $response = $this->post('/api/empleados', [
            'nombre' => 'Ana',
            'apellido_paterno' => 'Ruiz',
            'apellido_materno' => 'Lopez',
            'sexo' => 'femenino',
            'telefono' => '6671112233',
            'correo' => 'ana@test.com',
            'numero_empleado' => 'EMP-001',
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'jefe_inmediato' => 'Luis Pérez',
            'fecha_ingreso' => '2026-08-01',
            'tipo_contrato' => 'indefinido',
            'turno' => 'matutino',
            'estatus' => 'activo',
            'sueldo' => 2500,
            'frecuencia_sueldo' => 'quincenal',
            'contacto_emergencia_nombre' => 'María Ruiz',
            'parentesco' => 'Madre',
            'contacto_emergencia_telefono' => '6679998877',
            'image' => UploadedFile::fake()->create('foto.jpg', 100, 'image/jpeg'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.empleado.first_name', 'Ana')
            ->assertJsonPath('data.empleado.role.name', 'Cajero')
            ->assertJsonPath('data.empleado.salary', '2500.00')
            ->assertJsonPath('data.empleado.salary_frequency', 'quincenal');

        $imagePath = $response->json('data.empleado.image');
        $this->assertNotNull($imagePath);
        Storage::disk('empleados')->assertExists($imagePath);

        $this->assertDatabaseHas('empleados', [
            'negocio_id' => $negocio->id,
            'employee_number' => 'EMP-001',
            'role_id' => $role->id,
        ]);
    }

    public function test_updating_empleado_sucursal_or_role_syncs_linked_staff(): void
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        $sucursalCentro = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Centro',
            'is_active' => true,
        ]);

        $sucursalNorte = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Norte',
            'is_active' => true,
        ]);

        $roleCajero = $negocio->roles()->create([
            'name' => 'Cajero',
            'permissions' => Role::defaultPermissions(),
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $roleGerente = $negocio->roles()->create([
            'name' => 'Gerente',
            'permissions' => Role::defaultPermissions(),
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $empleado = $negocio->empleados()->create([
            'sucursal_id' => $sucursalCentro->id,
            'role_id' => $roleCajero->id,
            'first_name' => 'Ana',
            'paternal_surname' => 'Ruiz',
            'employee_number' => 'EMP-010',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $staff = $negocio->staff()->create([
            'username' => 'ana.ruiz',
            'password' => 'secreto123',
            'sucursal_id' => $sucursalCentro->id,
            'role_id' => $roleCajero->id,
            'empleado_id' => $empleado->id,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson("/api/empleados/{$empleado->id}", [
            'sucursal_id' => $sucursalNorte->id,
            'role_id' => $roleGerente->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.empleado.sucursal_id', $sucursalNorte->id)
            ->assertJsonPath('data.empleado.role_id', $roleGerente->id);

        $this->assertDatabaseHas('staff', [
            'id' => $staff->id,
            'sucursal_id' => $sucursalNorte->id,
            'role_id' => $roleGerente->id,
            'updated_by' => $user->id,
        ]);
    }
}
