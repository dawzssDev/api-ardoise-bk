<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\TurnoCajaCorte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TurnoCajaCorteTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_cortes_store_only_the_current_tramo(): void
    {
        [$user, $negocio, $sucursal, $producto, $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 2000,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Tramo 1',
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 100]],
        ])->assertCreated();

        $this->postJson("/api/turnos-caja/{$turnoId}/cortes", [
            'efectivo_real_cajera' => 10000,
            'observaciones' => 'Primer corte',
        ])
            ->assertCreated()
            ->assertJsonPath('data.corte.tipo_corte', TurnoCajaCorte::TIPO_PARCIAL)
            ->assertJsonPath('data.corte.total_ventas_efectivo', '100.00')
            ->assertJsonPath('data.corte.total_ventas_tarjeta', '0.00')
            ->assertJsonPath('data.corte.efectivo_real_cajera', '10000.00')
            ->assertJsonPath('data.turno.status', TurnoCaja::STATUS_ABIERTO);

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Tramo 2',
            'tipo_pago' => 'tarjeta',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 80]],
        ])->assertCreated();

        $this->postJson('/api/turnos-caja/actual/cortes', [
            'efectivo_real' => 10000,
        ])
            ->assertCreated()
            ->assertJsonPath('data.corte.tipo_corte', TurnoCajaCorte::TIPO_PARCIAL)
            ->assertJsonPath('data.corte.total_ventas_efectivo', '0.00')
            ->assertJsonPath('data.corte.total_ventas_tarjeta', '80.00')
            ->assertJsonPath('data.corte.total_ventas', '80.00');

        $this->getJson("/api/turnos-caja/{$turnoId}/cortes")
            ->assertOk()
            ->assertJsonPath('data.cortes.0.tipo_corte', TurnoCajaCorte::TIPO_PARCIAL)
            ->assertJsonPath('data.cortes.1.total_ventas_tarjeta', '80.00')
            ->assertJsonPath('data.tramo_actual.total_ventas', 0)
            ->assertJsonPath('data.acumulado.total_ventas', 180)
            ->assertJsonPath('data.acumulado.efectivo_real_cajera', 20000);

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Tramo cierre',
            'tipo_pago' => 'transferencia',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 40]],
        ])->assertCreated();

        $this->getJson("/api/turnos-caja/{$turnoId}/cortes")
            ->assertOk()
            ->assertJsonPath('data.tramo_actual.total_ventas_transferencia', 40)
            ->assertJsonPath('data.tramo_actual.total_ventas', 40);
    }

    public function test_cajera_close_creates_tipo_cierre_and_sums_all_cortes(): void
    {
        [$user, $negocio, $sucursal, $producto, $staff] = $this->seedCajaContext();

        $permissions = Role::defaultPermissions();
        $permissions['corteCaja'] = false;
        $permissions['corteCajaCajera'] = true;
        $staff->role->update(['permissions' => $permissions]);

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 2000,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Tramo 1',
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 100]],
        ])->assertCreated();

        $this->postJson("/api/turnos-caja/{$turnoId}/cortes", [
            'efectivo_real_cajera' => 10000,
        ])->assertCreated();

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Tramo 2',
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 100]],
        ])->assertCreated();

        $this->postJson("/api/turnos-caja/{$turnoId}/cortes", [
            'efectivo_real_cajera' => 10000,
        ])->assertCreated();

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Tramo 3',
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 70]],
        ])->assertCreated();

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 7000,
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.status', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_ABIERTO)
            ->assertJsonPath('data.turno.total_ventas_efectivo', '270.00')
            ->assertJsonPath('data.turno.total_ventas', '270.00')
            ->assertJsonPath('data.turno.efectivo_real_cajera', '27000.00');

        $this->assertDatabaseCount('tb_turnos_cajas_cortes', 3);
        $this->assertDatabaseHas('tb_turnos_cajas_cortes', [
            'turno_caja_id' => $turnoId,
            'tipo_corte' => TurnoCajaCorte::TIPO_CIERRE,
            'total_ventas_efectivo' => 70,
            'efectivo_real_cajera' => 7000,
        ]);

        $this->postJson("/api/turnos-caja/{$turnoId}/cortes", [
            'efectivo_real_cajera' => 1,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes registrar un corte parcial en un turno cerrado.');
    }

    public function test_cannot_create_partial_corte_without_open_turno(): void
    {
        [, , , , $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $this->postJson('/api/turnos-caja/actual/cortes', [
            'efectivo_real_cajera' => 100,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Debes iniciar turno de caja antes de registrar un corte parcial.');
    }

    /**
     * @return array{0: User, 1: \App\Models\Negocio, 2: Sucursal, 3: \App\Models\Producto, 4: \App\Models\Staff}
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
            'employee_number' => 'EMP-CAJ-CORTE',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $staff = $negocio->staff()->create([
            'username' => 'ana.corte',
            'password' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'empleado_id' => $empleado->id,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $categoria = $negocio->categoriaProductos()->create(['name' => 'General']);
        $producto = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Producto',
            'price' => 50,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $negocio, $sucursal, $producto, $staff];
    }
}
