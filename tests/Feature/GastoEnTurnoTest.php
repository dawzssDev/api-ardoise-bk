<?php

namespace Tests\Feature;

use App\Models\GastoEnTurno;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GastoEnTurnoTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_register_gastos_on_open_turno(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 500,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'descripcion' => 'Pago a distribuidor de refrescos',
            'monto' => 150.5,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.gasto.tipo_gasto', GastoEnTurno::TIPO_PAGO_PROVEEDOR)
            ->assertJsonPath('data.gasto.tipo_gasto_label', 'Pago proveedor')
            ->assertJsonPath('data.gasto.descripcion', 'Pago a distribuidor de refrescos')
            ->assertJsonPath('data.gasto.monto', '150.50')
            ->assertJsonPath('data.gasto.negocio_id', $negocio->id)
            ->assertJsonPath('data.gasto.sucursal_id', $sucursal->id)
            ->assertJsonPath('data.gasto.id_cajero', $staff->id)
            ->assertJsonPath('data.gasto.turno_caja_id', $turnoId)
            ->assertJsonPath('data.turno.total_pagos_proveedores', '150.50');

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo_gasto' => 'gasto_operativo',
            'descripcion' => 'Compra de bolsas',
            'monto' => 40,
        ])->assertCreated();

        $this->postJson('/api/turnos-caja/actual/gastos', [
            'tipo' => 'Retiro de efectivo',
            'descripcion' => 'Retiro para cambio',
            'monto' => 20,
        ])
            ->assertCreated()
            ->assertJsonPath('data.gasto.tipo_gasto', GastoEnTurno::TIPO_RETIRO_EFECTIVO)
            ->assertJsonPath('data.turno.total_retiros_efectivo', '20.00');

        $this->assertDatabaseCount('tb_gastos_en_turno', 3);
        $this->assertDatabaseHas('tb_turnos_cajas', [
            'id' => $turnoId,
            'total_pagos_proveedores' => 150.50,
            'total_gastos_operativos' => 40,
            'total_retiros_efectivo' => 20,
        ]);

        $this->getJson("/api/turnos-caja/{$turnoId}/gastos")
            ->assertOk()
            ->assertJsonPath('data.turno_id', $turnoId)
            ->assertJsonPath('data.totales.pago_proveedor', 150.5)
            ->assertJsonPath('data.totales.gasto_operativo', 40)
            ->assertJsonPath('data.totales.retiro_efectivo', 20)
            ->assertJsonPath('data.totales.total', 210.5)
            ->assertJsonPath('data.meta.total', 3);
    }

    public function test_cannot_register_gasto_without_open_turno(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $this->postJson('/api/turnos-caja/actual/gastos', [
            'tipo' => 'Gasto operativo',
            'descripcion' => 'Sin turno',
            'monto' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Debes iniciar turno de caja antes de registrar gastos.');
    }

    public function test_cannot_register_gasto_on_closed_turno(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 100,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 100,
        ])->assertOk();

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'descripcion' => 'Ya cerrado',
            'monto' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes registrar gastos en un turno cerrado.');
    }

    public function test_staff_cannot_register_gasto_on_another_cajera_turno(): void
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
            'employee_number' => 'EMP-CAJ-2',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $otra = $negocio->staff()->create([
            'username' => 'bety.caja',
            'password' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'empleado_id' => $empleado->id,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($otra);
        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Gasto operativo',
            'descripcion' => 'No es mi turno',
            'monto' => 15,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'No puedes registrar gastos en el turno de otra cajera.');
    }

    public function test_corte_de_caja_subtracts_gastos_from_diferencia(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 200,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'descripcion' => 'Proveedor',
            'monto' => 30,
        ])->assertCreated();

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Gasto operativo',
            'descripcion' => 'Operativo',
            'monto' => 10,
        ])->assertCreated();

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Retiro de efectivo',
            'descripcion' => 'Retiro',
            'monto' => 5,
        ])->assertCreated();

        $this->getJson("/api/turnos-caja/{$turnoId}/preview")
            ->assertOk()
            ->assertJsonPath('data.preview.total_pagos_proveedores', 30)
            ->assertJsonPath('data.preview.total_gastos_operativos', 10)
            ->assertJsonPath('data.preview.total_retiros_efectivo', 5);

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 55,
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.total_pagos_proveedores', '30.00')
            ->assertJsonPath('data.turno.total_gastos_operativos', '10.00')
            ->assertJsonPath('data.turno.total_retiros_efectivo', '5.00')
            ->assertJsonPath('data.turno.efectivo_esperado', '0.00')
            ->assertJsonPath('data.turno.diferencia', '-100.00');
    }

    public function test_gasto_validation_rejects_invalid_tipo_and_monto(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 50,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'otro',
            'descripcion' => 'Inválido',
            'monto' => 10,
        ])->assertStatus(422);

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'descripcion' => 'Cero',
            'monto' => 0,
        ])->assertStatus(422);

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
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
            'employee_number' => 'EMP-CAJ-G',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $staff = $negocio->staff()->create([
            'username' => 'ana.gastos',
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
