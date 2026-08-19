<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepositoEnTurnoTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_register_depositos_on_open_turno(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 500,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/depositos", [
            'descripcion' => 'Depósito de cambio',
            'monto' => 200.5,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deposito.descripcion', 'Depósito de cambio')
            ->assertJsonPath('data.deposito.monto', '200.50')
            ->assertJsonPath('data.deposito.negocio_id', $negocio->id)
            ->assertJsonPath('data.deposito.sucursal_id', $sucursal->id)
            ->assertJsonPath('data.deposito.id_cajero', $staff->id)
            ->assertJsonPath('data.deposito.turno_caja_id', $turnoId)
            ->assertJsonPath('data.turno.total_depositos_efectivo', '200.50');

        $this->postJson('/api/turnos-caja/actual/depositos', [
            'concepto' => 'Depósito extra',
            'importe' => 50,
        ])
            ->assertCreated()
            ->assertJsonPath('data.deposito.descripcion', 'Depósito extra')
            ->assertJsonPath('data.turno.total_depositos_efectivo', '250.50');

        $this->assertDatabaseCount('tb_deposito_efectivo_en_turno', 2);
        $this->assertDatabaseHas('tb_turnos_cajas', [
            'id' => $turnoId,
            'total_depositos_efectivo' => 250.50,
        ]);

        $this->getJson("/api/turnos-caja/{$turnoId}/depositos")
            ->assertOk()
            ->assertJsonPath('data.turno_id', $turnoId)
            ->assertJsonPath('data.totales.total', 250.5)
            ->assertJsonPath('data.meta.total', 2);
    }

    public function test_cannot_register_deposito_without_open_turno(): void
    {
        [, , , $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $this->postJson('/api/turnos-caja/actual/depositos', [
            'descripcion' => 'Sin turno',
            'monto' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Debes iniciar turno de caja antes de registrar depósitos.');
    }

    public function test_cannot_register_deposito_on_closed_turno(): void
    {
        [, , , $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 100,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 100,
        ])->assertOk();

        $this->postJson("/api/turnos-caja/{$turnoId}/depositos", [
            'descripcion' => 'Ya cerrado',
            'monto' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes registrar depósitos en un turno cerrado.');
    }

    public function test_staff_cannot_register_deposito_on_another_cajera_turno(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 80,
        ])->assertCreated()->json('data.turno.id');

        $permissions = Role::defaultPermissions();
        $permissions['corteCajaCajera'] = true;

        $role = $negocio->roles()->create([
            'name' => 'Otra cajera',
            'permissions' => $permissions,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $empleado = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'first_name' => 'Bety',
            'paternal_surname' => 'Caja',
            'employee_number' => 'EMP-DEP-2',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $otra = $negocio->staff()->create([
            'username' => 'bety.deposito',
            'password' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'empleado_id' => $empleado->id,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($otra);
        $this->postJson("/api/turnos-caja/{$turnoId}/depositos", [
            'descripcion' => 'No es mi turno',
            'monto' => 15,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'No puedes registrar depósitos en el turno de otra cajera.');
    }

    public function test_deposito_validation_rejects_invalid_monto(): void
    {
        [, , , $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 50,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/depositos", [
            'descripcion' => 'Cero',
            'monto' => 0,
        ])->assertStatus(422);

        $this->postJson("/api/turnos-caja/{$turnoId}/depositos", [
            'monto' => 10,
        ])->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: \App\Models\Negocio, 2: Sucursal, 3: \App\Models\Staff}
     */
    private function seedCajaContext(): array
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Caja',
            'phone' => '5511111111',
            'needs_invoice' => false,
        ]);

        $sucursal = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Centro',
            'is_active' => true,
        ]);

        $permissions = Role::defaultPermissions();
        $permissions['corteCaja'] = true;

        $role = $negocio->roles()->create([
            'name' => 'Cajera',
            'permissions' => $permissions,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $empleado = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'first_name' => 'Ana',
            'paternal_surname' => 'Caja',
            'employee_number' => 'EMP-DEP',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $staff = $negocio->staff()->create([
            'username' => 'ana.depositos',
            'password' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'empleado_id' => $empleado->id,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $negocio, $sucursal, $staff];
    }
}
