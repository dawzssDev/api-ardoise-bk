<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TurnoCajaTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_must_open_caja_before_creating_orden(): void
    {
        [$user, $negocio, $sucursal, $producto, $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Mesa 1',
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Debes iniciar turno de caja antes de realizar ventas.');

        $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 500,
        ])
            ->assertCreated()
            ->assertJsonPath('data.turno.status', TurnoCaja::STATUS_ABIERTO)
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_ABIERTO)
            ->assertJsonPath('data.turno.fondo_inicial', '500.00');

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Mesa 1',
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1]],
        ])->assertCreated();

        $this->assertDatabaseHas('tb_ventas', [
            'negocio_id' => $negocio->id,
            'sucursal_id' => $sucursal->id,
            'payment_type' => 'efectivo',
            'id_user' => $staff->id,
        ]);
    }

    public function test_corte_de_caja_sums_payment_methods(): void
    {
        [$user, $negocio, $sucursal, $producto, $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $open = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 200,
        ])->assertCreated();

        $turnoId = $open->json('data.turno.id');

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Efectivo',
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 2, 'precio' => 50]],
        ])->assertCreated();

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Tarjeta',
            'tipo_pago' => 'tarjeta',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 80]],
        ])->assertCreated();

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Transferencia',
            'tipo_pago' => 'transferencia',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 40]],
        ])->assertCreated();

        $this->getJson("/api/turnos-caja/{$turnoId}/preview")
            ->assertOk()
            ->assertJsonPath('data.preview.total_ventas_efectivo', 100)
            ->assertJsonPath('data.preview.total_ventas_tarjeta', 80)
            ->assertJsonPath('data.preview.total_ventas_transferencia', 40)
            ->assertJsonPath('data.preview.total_ventas', 220)
            ->assertJsonPath('data.preview.efectivo_esperado', 100);

        // Staff con corteCaja = cierre administrador (completo)
        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 90,
            'observaciones' => 'Faltante de 10',
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.status', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turno.total_ventas_efectivo', '100.00')
            ->assertJsonPath('data.turno.total_ventas_tarjeta', '80.00')
            ->assertJsonPath('data.turno.total_ventas_transferencia', '40.00')
            ->assertJsonPath('data.turno.total_ventas', '220.00')
            ->assertJsonPath('data.turno.efectivo_esperado', '100.00')
            ->assertJsonPath('data.turno.efectivo_real', '90.00')
            ->assertJsonPath('data.turno.diferencia', '10.00');

        $this->assertDatabaseCount('tb_ventas', 3);
    }

    public function test_login_and_me_expose_caja_status_for_staff(): void
    {
        [$user, $negocio, $sucursal, $producto, $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.caja.caja_abierta', false)
            ->assertJsonPath('data.caja.requiere_abrir_caja', true)
            ->assertJsonPath('data.staff.role.permissions.corteCaja', true);

        $this->postJson('/api/turnos-caja/abrir', ['fondo_inicial' => 10])->assertCreated();

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.caja.caja_abierta', true)
            ->assertJsonPath('data.caja.requiere_abrir_caja', false)
            ->assertJsonPath('data.caja.turno.status', TurnoCaja::STATUS_ABIERTO);
    }

    public function test_staff_without_corte_caja_cannot_close_turno(): void
    {
        [$user, $negocio, $sucursal, $producto, $staff] = $this->seedCajaContext();

        $staff->role->update([
            'permissions' => Role::defaultPermissions(),
        ]);

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 100,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 100,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'No tienes permiso para realizar el corte de caja.');
    }

    public function test_staff_with_corte_caja_cajera_can_close_turno(): void
    {
        [$user, $negocio, $sucursal, $producto, $staff] = $this->seedCajaContext();

        $permissions = Role::defaultPermissions();
        $permissions['corteCaja'] = false;
        $permissions['corteCajaCajera'] = true;
        $staff->role->update(['permissions' => $permissions]);

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 100,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 100,
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.status', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_ABIERTO)
            ->assertJsonPath('data.turno.efectivo_real_cajera', '100.00');

        $this->assertNotNull(TurnoCaja::query()->find($turnoId)?->fecha_cierre_cajera);
        $this->assertNull(TurnoCaja::query()->find($turnoId)?->fecha_cierre);
    }

    public function test_cannot_open_turno_while_admin_cut_is_pending(): void
    {
        [$user, $negocio, $sucursal, $producto, $staff] = $this->seedCajaContext();

        $permissions = Role::defaultPermissions();
        $permissions['corteCaja'] = false;
        $permissions['corteCajaCajera'] = true;
        $staff->role->update(['permissions' => $permissions]);

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 100,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 100,
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.status', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_ABIERTO);

        $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 50,
        ])
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                'Existe un corte abierto para el administrador. No se puede abrir turno hasta que se cierre ese corte.'
            );

        Sanctum::actingAs($user);
        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 100,
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_CERRADO);

        Sanctum::actingAs($staff);
        $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 50,
        ])
            ->assertCreated()
            ->assertJsonPath('data.turno.status', TurnoCaja::STATUS_ABIERTO)
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_ABIERTO);
    }

    public function test_owner_can_close_turno_without_role_permission(): void
    {
        [$user, $negocio, $sucursal, $producto, $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 50,
            'sucursal_id' => $sucursal->id,
        ])->assertCreated()->json('data.turno.id');

        Sanctum::actingAs($user);
        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 50,
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.status', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_CERRADO);
    }

    public function test_index_includes_turnos_administrador_pending(): void
    {
        [$user, $negocio, $sucursal, $producto, $staff] = $this->seedCajaContext();

        $permissions = Role::defaultPermissions();
        $permissions['corteCaja'] = false;
        $permissions['corteCajaCajera'] = true;
        $staff->role->update(['permissions' => $permissions]);

        Sanctum::actingAs($staff);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 80,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 80,
        ])->assertOk();

        Sanctum::actingAs($user);
        $this->getJson('/api/turnos-caja?sucursal_id='.$sucursal->id.'&status=cerrado&page=1')
            ->assertOk()
            ->assertJsonPath('data.turnos.0.id', $turnoId)
            ->assertJsonPath('data.turnos.0.status', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turnosAdminsitrador.0.id', $turnoId)
            ->assertJsonPath('data.turnosAdminsitrador.0.status_administrador', TurnoCaja::STATUS_ABIERTO)
            ->assertJsonPath('data.turnosAdministrador.0.id', $turnoId);
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
            'employee_number' => 'EMP-CAJ',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $staff = $negocio->staff()->create([
            'username' => 'ana.caja',
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
