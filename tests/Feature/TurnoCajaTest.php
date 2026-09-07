<?php

namespace Tests\Feature;

use App\Models\MaeCuentaContaSuc;
use App\Models\MaeCuentaContaSucDetalle;
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
            ->assertJsonPath('data.turno.status_gerencia', TurnoCaja::STATUS_ABIERTO)
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
        $this->assertDatabaseHas('tb_turnos_cajas_cortes', [
            'turno_caja_id' => $turnoId,
            'tipo_corte' => 2,
            'total_ventas_efectivo' => 100,
            'total_ventas_tarjeta' => 80,
            'total_ventas_transferencia' => 40,
            'total_ventas' => 220,
        ]);
        $this->assertDatabaseCount('tb_turnos_cajas_cortes', 1);
    }

    public function test_status_gerencia_is_saved_on_create_and_update_without_validation(): void
    {
        [, , , , $staff] = $this->seedCajaContext();

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 100,
        ])
            ->assertCreated()
            ->assertJsonPath('data.turno.status_gerencia', TurnoCaja::STATUS_ABIERTO)
            ->json('data.turno.id');

        $this->getJson("/api/turnos-caja/{$turnoId}")
            ->assertOk()
            ->assertJsonPath('data.turno.status_gerencia', TurnoCaja::STATUS_ABIERTO);

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 100,
            'status_gerencia' => 'cerrado',
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.status_gerencia', 'cerrado');

        $this->assertDatabaseHas('tb_turnos_cajas', [
            'id' => $turnoId,
            'status_gerencia' => 'cerrado',
        ]);
    }

    public function test_gerencia_can_close_status_gerencia_after_admin_already_closed(): void
    {
        [$user, $negocio, $sucursal, $producto, $staff] = $this->seedCajaContext();

        $permissions = Role::defaultPermissions();
        $permissions['corteCaja'] = false;
        $permissions['corteCajaCajera'] = true;
        $staff->role->update(['permissions' => $permissions]);

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'CUENTA MATRIZ',
            'saldo' => 1000,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subcuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'SUCURSAL '.$sucursal->name,
            'saldo' => 500,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($staff);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 100,
        ])->assertCreated()->json('data.turno.id');

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

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 200,
        ])->assertOk();

        Sanctum::actingAs($user);
        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 200,
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turno.status_gerencia', TurnoCaja::STATUS_ABIERTO)
            ->assertJsonPath('data.turno.total_ventas_efectivo', '100.00')
            ->assertJsonPath('data.turno.total_ventas_tarjeta', '80.00');

        $this->putJson("/api/turnos-caja/{$turnoId}/validacion-gerencia", [
            'efectivo_gerencia' => 90,
            'terminal_gerencia' => 70,
            'diferencia_gerencia' => -20,
        ])->assertOk();

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'status_gerencia' => 'cerrado',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turno.status_gerencia', TurnoCaja::STATUS_CERRADO);

        $this->assertDatabaseHas('tb_turnos_cajas', [
            'id' => $turnoId,
            'status_administrador' => TurnoCaja::STATUS_CERRADO,
            'status_gerencia' => TurnoCaja::STATUS_CERRADO,
        ]);

        $this->assertDatabaseHas('mae_cuenta_conta_suc_detalle', [
            'tipo_movimiento' => MaeCuentaContaSucDetalle::TIPO_VENTA_EFECTIVO,
            'cuenta_origen_id' => $subcuenta->id,
            'cuenta_destino_id' => $maestra->id,
            'monto_movimiento' => 90.00,
            'status' => MaeCuentaContaSucDetalle::STATUS_ACEPTADO,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc_detalle', [
            'tipo_movimiento' => MaeCuentaContaSucDetalle::TIPO_VENTA_TARJETA,
            'cuenta_origen_id' => $subcuenta->id,
            'cuenta_destino_id' => $maestra->id,
            'monto_movimiento' => 70.00,
            'status' => MaeCuentaContaSucDetalle::STATUS_ACEPTADO,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc_detalle', [
            'tipo_movimiento' => MaeCuentaContaSucDetalle::TIPO_COMISION_TERMINAL,
            'mae_cuenta_conta_suc_id' => $maestra->id,
            'cuenta_origen_id' => $maestra->id,
            'cuenta_destino_id' => null,
            'monto_movimiento' => 2.10,
            'status' => MaeCuentaContaSucDetalle::STATUS_ACEPTADO,
        ]);

        // Abona matriz y descuenta comisión de terminal (3% de 70 = 2.10); sucursal no se descuenta
        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $maestra->id, 'saldo' => 1157.90]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $subcuenta->id, 'saldo' => 500.00]);

        $descripcion = MaeCuentaContaSucDetalle::query()
            ->where('tipo_movimiento', MaeCuentaContaSucDetalle::TIPO_VENTA_EFECTIVO)
            ->value('descripcion_movimiento');
        $this->assertStringContainsString('CORTE del', (string) $descripcion);
        $this->assertStringContainsString($sucursal->name, (string) $descripcion);

        $descripcionComision = MaeCuentaContaSucDetalle::query()
            ->where('tipo_movimiento', MaeCuentaContaSucDetalle::TIPO_COMISION_TERMINAL)
            ->value('descripcion_movimiento');
        $this->assertStringContainsString('comisión de terminal 3%', (string) $descripcionComision);
        $this->assertStringContainsString($sucursal->name, (string) $descripcionComision);
    }

    public function test_gerencia_close_creates_comision_terminal_salida_from_corte_tarjeta(): void
    {
        [$user, $negocio, $sucursal] = $this->seedCajaContext();
        $negocio->update(['comision_venta_tarjeta' => 3]);

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'CUENTA MATRIZ',
            'saldo' => 0,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'SUCURSAL '.$sucursal->name,
            'saldo' => 0,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'sucursal_id' => $sucursal->id,
            'fondo_inicial' => 100,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 100,
        ])->assertOk();

        $this->putJson("/api/turnos-caja/{$turnoId}/validacion-gerencia", [
            'efectivo_contado' => 0,
            'corte_tarjeta' => 1000,
            'diferencia_gerencia' => 0,
        ])->assertOk();

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'status_gerencia' => 'cerrado',
        ])->assertOk();

        $this->assertDatabaseHas('mae_cuenta_conta_suc_detalle', [
            'tipo_movimiento' => MaeCuentaContaSucDetalle::TIPO_VENTA_TARJETA,
            'cuenta_destino_id' => $maestra->id,
            'monto_movimiento' => 1000.00,
            'status' => MaeCuentaContaSucDetalle::STATUS_ACEPTADO,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc_detalle', [
            'tipo_movimiento' => MaeCuentaContaSucDetalle::TIPO_COMISION_TERMINAL,
            'mae_cuenta_conta_suc_id' => $maestra->id,
            'cuenta_origen_id' => $maestra->id,
            'cuenta_destino_id' => null,
            'monto_movimiento' => 30.00,
            'status' => MaeCuentaContaSucDetalle::STATUS_ACEPTADO,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $maestra->id,
            'saldo' => 970.00,
        ]);
    }

    public function test_gerencia_close_uses_negocio_comision_venta_tarjeta(): void
    {
        [$user, $negocio, $sucursal] = $this->seedCajaContext();
        $negocio->update(['comision_venta_tarjeta' => 10]);

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'CUENTA MATRIZ',
            'saldo' => 0,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'SUCURSAL',
            'saldo' => 0,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'sucursal_id' => $sucursal->id,
            'fondo_inicial' => 100,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 100,
        ])->assertOk();

        $this->putJson("/api/turnos-caja/{$turnoId}/validacion-gerencia", [
            'efectivo_gerencia' => 0,
            'terminal_gerencia' => 1000,
            'diferencia_gerencia' => 0,
        ])->assertOk();

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'status_gerencia' => 'cerrado',
        ])->assertOk();

        $this->assertDatabaseHas('mae_cuenta_conta_suc_detalle', [
            'tipo_movimiento' => MaeCuentaContaSucDetalle::TIPO_COMISION_TERMINAL,
            'monto_movimiento' => 100.00,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $maestra->id,
            'saldo' => 900.00,
        ]);
    }

    public function test_admin_close_subtracts_turno_gastos_from_sucursal_account(): void
    {
        [$user, $negocio, $sucursal, $producto, $staff] = $this->seedCajaContext();

        $permissions = Role::defaultPermissions();
        $permissions['corteCaja'] = false;
        $permissions['corteCajaCajera'] = true;
        $staff->role->update(['permissions' => $permissions]);

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'CUENTA MATRIZ',
            'saldo' => 1000,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subcuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'SUCURSAL '.$sucursal->name,
            'saldo' => 400,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);
        $depositoId = $this->postJson('/api/cuentas-contables-detalles', [
            'idMaeCuentaContaSuc' => $maestra->id,
            'tipoMovimiento' => 'transferencia',
            'descripcionMovimiento' => 'Envío a sucursal',
            'montoMovimiento' => 1000,
            'cuentaOrigen' => $maestra->id,
            'cuentaDestino' => $subcuenta->id,
        ])->assertCreated()->json('data.detalle.id');

        $encargadoPermissions = Role::defaultPermissions();
        $encargadoPermissions['corteCaja'] = true;
        $encargadoRole = $negocio->roles()->create([
            'name' => 'Encargado sucursal',
            'permissions' => $encargadoPermissions,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $empleadoEncargado = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $encargadoRole->id,
            'first_name' => 'Encargado',
            'paternal_surname' => 'Sucursal',
            'employee_number' => 'EMP-ENC-CTA',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $encargado = $negocio->staff()->create([
            'username' => 'encargado.abastos',
            'password' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $encargadoRole->id,
            'empleado_id' => $empleadoEncargado->id,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($encargado);
        $this->postJson("/api/cuentas-contables-detalles/{$depositoId}/aceptar")->assertOk();
        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $subcuenta->id,
            'saldo' => 1400,
        ]);

        Sanctum::actingAs($staff);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 1200,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Gasto operativo',
            'descripcion' => 'Gasto 1',
            'monto' => 500,
        ])->assertCreated();
        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Gasto operativo',
            'descripcion' => 'Gasto 2',
            'monto' => 500,
        ])->assertCreated();

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 200,
        ])->assertOk();

        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $subcuenta->id,
            'saldo' => 1400,
        ]);

        Sanctum::actingAs($encargado);
        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 200,
        ])->assertOk();

        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $subcuenta->id,
            'saldo' => 400,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc_detalle', [
            'mae_cuenta_conta_suc_id' => $subcuenta->id,
            'tipo_movimiento' => MaeCuentaContaSucDetalle::TIPO_GASTO_OPERATIVO,
            'monto_movimiento' => 1000,
            'cuenta_destino_id' => null,
            'status' => MaeCuentaContaSucDetalle::STATUS_ACEPTADO,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $maestra->id,
            'saldo' => 0,
        ]);

        Sanctum::actingAs($user);
        $this->putJson("/api/turnos-caja/{$turnoId}/validacion-gerencia", [
            'efectivo_gerencia' => 0,
            'terminal_gerencia' => 0,
            'diferencia_gerencia' => 0,
        ])->assertOk();
        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'status_gerencia' => 'cerrado',
        ])->assertOk();

        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $subcuenta->id,
            'saldo' => 400,
        ]);
    }

    public function test_gerencia_close_requires_efectivo_and_terminal_gerencia(): void
    {
        [$user, $negocio, $sucursal] = $this->seedCajaContext();

        $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'CUENTA MATRIZ',
            'saldo' => 0,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'SUCURSAL',
            'saldo' => 0,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'sucursal_id' => $sucursal->id,
            'fondo_inicial' => 100,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 100,
        ])->assertOk();

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'status_gerencia' => 'cerrado',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
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

    public function test_monto_maximo_efectivo_does_not_block_sales(): void
    {
        [$user, $negocio, $sucursal, $producto, $staff] = $this->seedCajaContext();
        $sucursal->update(['monto_maximo_efectivo' => 100]);

        Sanctum::actingAs($staff);

        $this->postJson('/api/turnos-caja/abrir', ['fondo_inicial' => 80])
            ->assertCreated()
            ->assertJsonPath('data.turno.monto_maximo_efectivo', '100.00')
            ->assertJsonPath('data.turno.efectivo_en_caja', '80.00')
            ->assertJsonPath('data.turno.excede_monto_maximo_efectivo', false)
            ->assertJsonPath('data.turno.sucursal.monto_maximo_efectivo', '100.00');

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Mesa 1',
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 50]],
        ])->assertCreated();

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.staff.sucursal.monto_maximo_efectivo', '100.00')
            ->assertJsonPath('data.caja.turno.efectivo_en_caja', '130.00')
            ->assertJsonPath('data.caja.turno.excede_monto_maximo_efectivo', true);

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Mesa 2',
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 50]],
        ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.caja.turno.efectivo_en_caja', '180.00')
            ->assertJsonPath('data.caja.turno.excede_monto_maximo_efectivo', true);
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

    public function test_staff_with_gerente_admo_can_list_turnos_by_sucursal(): void
    {
        [$user, $negocio, $sucursal, $producto, $cajera] = $this->seedCajaContext();

        $cajeraPermissions = Role::defaultPermissions();
        $cajeraPermissions['corteCaja'] = false;
        $cajeraPermissions['corteCajaCajera'] = true;
        $cajera->role->update(['permissions' => $cajeraPermissions]);

        Sanctum::actingAs($cajera);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 80,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 80,
        ])->assertOk();

        $gerentePermissions = Role::defaultPermissions();
        $gerentePermissions['corteCaja'] = false;
        $gerentePermissions['corteCajaCajera'] = false;
        $gerentePermissions['corteCajaGerenteAdmo'] = true;

        $gerenteRole = $negocio->roles()->create([
            'name' => 'Gerente sucursal',
            'permissions' => $gerentePermissions,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $empleadoGerente = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $gerenteRole->id,
            'first_name' => 'Luis',
            'paternal_surname' => 'Gerente',
            'employee_number' => 'EMP-GER',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $gerente = $negocio->staff()->create([
            'username' => 'luis.gerente',
            'password' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $gerenteRole->id,
            'empleado_id' => $empleadoGerente->id,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($gerente);
        $this->getJson('/api/turnos-caja?sucursal_id='.$sucursal->id.'&status=cerrado')
            ->assertOk()
            ->assertJsonPath('data.turnos.0.id', $turnoId)
            ->assertJsonPath('data.turnosAdministrador.0.id', $turnoId)
            ->assertJsonPath('data.turnosAdministrador.0.status_administrador', TurnoCaja::STATUS_ABIERTO);

        $this->getJson('/api/turnos-caja?sucursalId='.$sucursal->id)
            ->assertOk()
            ->assertJsonPath('data.turnosAdministrador.0.id', $turnoId);
    }

    public function test_maestro_can_update_validacion_gerencia_fields(): void
    {
        [$user, , $sucursal] = $this->seedCajaContext();

        Sanctum::actingAs($user);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'sucursal_id' => $sucursal->id,
            'fondo_inicial' => 100,
        ])->assertCreated()->json('data.turno.id');

        $this->putJson("/api/turnos-caja/{$turnoId}/validacion-gerencia", [
            'efectivo_gerencia' => 250.50,
            'terminal_gerencia' => 80,
            'direfencia_Gerencia' => -10.5,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.turno.efectivo_gerencia', '250.50')
            ->assertJsonPath('data.turno.terminal_gerencia', '80.00')
            ->assertJsonPath('data.turno.diferencia_gerencia', '-10.50')
            ->assertJsonPath('data.turno.user_id_dateValidationGerencia', $user->id);

        $this->assertNotNull(
            $this->getJson("/api/turnos-caja/{$turnoId}")->json('data.turno.dateValidationGerencia')
        );

        $this->assertDatabaseHas('tb_turnos_cajas', [
            'id' => $turnoId,
            'efectivo_gerencia' => 250.50,
            'terminal_gerencia' => 80,
            'diferencia_gerencia' => -10.50,
            'user_id_date_validation_gerencia' => $user->id,
        ]);
    }

    public function test_gerencia_sobrante_creates_entrada_on_cuenta_maestra(): void
    {
        [$user, $negocio, $sucursal] = $this->seedCajaContext();
        $sucursal->update(['name' => 'ABASTOS']);

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Cuenta maestra',
            'saldo' => 20000,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subcuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'ABASTOS',
            'saldo' => 400,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'sucursal_id' => $sucursal->id,
            'fondo_inicial' => 2000,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 2000,
        ])->assertOk();

        $turno = TurnoCaja::query()->findOrFail($turnoId);
        $fechaCorte = ($turno->fecha_cierre
            ?? $turno->fecha_cierre_cajera
            ?? $turno->fecha_apertura
            ?? now()
        )->timezone(config('app.timezone'))->format('d/m/Y');
        $descripcionSobrante = "SOBRANTE del CORTE del {$fechaCorte} de ABASTOS";

        $this->putJson("/api/turnos-caja/{$turnoId}/validacion-gerencia", [
            'efectivo_contado' => 0,
            'corte_tarjeta' => 0,
            'sobrante' => 1500,
            'faltante' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.diferencia_gerencia', '1500.00');

        $this->assertDatabaseHas('mae_cuenta_conta_suc_detalle', [
            'mae_cuenta_conta_suc_id' => $maestra->id,
            'tipo_movimiento' => MaeCuentaContaSucDetalle::TIPO_DEPOSITO,
            'cuenta_origen_id' => $subcuenta->id,
            'cuenta_destino_id' => $maestra->id,
            'monto_movimiento' => 1500.00,
            'descripcion_movimiento' => $descripcionSobrante,
            'status' => MaeCuentaContaSucDetalle::STATUS_ACEPTADO,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $maestra->id,
            'saldo' => 21500.00,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $subcuenta->id,
            'saldo' => 400.00,
        ]);

        $this->putJson("/api/turnos-caja/{$turnoId}/validacion-gerencia", [
            'efectivo_gerencia' => 0,
            'terminal_gerencia' => 0,
            'sobrante' => 1500,
        ])->assertOk();

        $this->assertSame(1, MaeCuentaContaSucDetalle::query()
            ->where('descripcion_movimiento', $descripcionSobrante)
            ->count());

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'status_gerencia' => 'cerrado',
        ])->assertOk();

        $this->assertSame(1, MaeCuentaContaSucDetalle::query()
            ->where('descripcion_movimiento', $descripcionSobrante)
            ->count());
        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $maestra->id,
            'saldo' => 21500.00,
        ]);
    }

    public function test_cajera_cannot_update_validacion_gerencia(): void
    {
        [$user, $negocio, $sucursal, , $staff] = $this->seedCajaContext();

        $permissions = Role::defaultPermissions();
        $permissions['corteCaja'] = false;
        $permissions['corteCajaGerenteAdmo'] = false;
        $permissions['corteCajaCajera'] = true;
        $staff->role->update(['permissions' => $permissions]);

        Sanctum::actingAs($staff);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 100,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/validacion-gerencia", [
            'efectivo_gerencia' => 100,
            'terminal_gerencia' => 0,
            'diferencia_gerencia' => 0,
        ])->assertForbidden();

        $this->assertDatabaseHas('tb_turnos_cajas', [
            'id' => $turnoId,
            'efectivo_gerencia' => null,
        ]);
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
