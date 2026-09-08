<?php

namespace Tests\Feature;

use App\Models\GastoEnTurno;
use App\Models\MaeCuentaContaSuc;
use App\Models\MaeCuentaContaSucDetalle;
use App\Models\Negocio;
use App\Models\Proveedor;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\TurnoCajaCorte;
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
        $proveedor = $this->createProveedor($user, $negocio);

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 500,
        ])
            ->assertCreated()
            ->assertJsonPath('data.turno.total_pagos_con_deposito', null)
            ->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'proveedor_id' => $proveedor->id,
            'descripcion' => 'Pago a distribuidor de refrescos',
            'monto' => 150.5,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.gasto.tipo_gasto', GastoEnTurno::TIPO_PAGO_PROVEEDOR)
            ->assertJsonPath('data.gasto.tipo_gasto_label', 'Pago proveedor')
            ->assertJsonPath('data.gasto.proveedor_id', $proveedor->id)
            ->assertJsonPath('data.gasto.proveedor.id', $proveedor->id)
            ->assertJsonPath('data.gasto.proveedor.name', 'Distribuidora Norte')
            ->assertJsonPath('data.gasto.descripcion', 'Pago a distribuidor de refrescos')
            ->assertJsonPath('data.gasto.monto', '150.50')
            ->assertJsonPath('data.gasto.origen', null)
            ->assertJsonPath('data.gasto.origen_label', 'Descuento a venta')
            ->assertJsonPath('data.gasto.negocio_id', $negocio->id)
            ->assertJsonPath('data.gasto.sucursal_id', $sucursal->id)
            ->assertJsonPath('data.gasto.id_cajero', $staff->id)
            ->assertJsonPath('data.gasto.turno_caja_id', $turnoId)
            ->assertJsonPath('data.turno.total_pagos_proveedores', '150.50')
            ->assertJsonPath('data.turno.total_pagos_con_deposito', '0.00');

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo_gasto' => 'gasto_operativo',
            'proveedor_id' => $proveedor->id,
            'descripcion' => 'Compra de bolsas',
            'monto' => 40,
        ])
            ->assertCreated()
            ->assertJsonPath('data.gasto.proveedor_id', null);

        $this->postJson('/api/turnos-caja/actual/gastos', [
            'tipo' => 'Retiro de efectivo',
            'descripcion' => 'Retiro para cambio',
            'monto' => 20,
        ])
            ->assertCreated()
            ->assertJsonPath('data.gasto.tipo_gasto', GastoEnTurno::TIPO_RETIRO_EFECTIVO)
            ->assertJsonPath('data.gasto.proveedor_id', null)
            ->assertJsonPath('data.turno.total_retiros_efectivo', '20.00');

        $this->assertDatabaseCount('tb_gastos_en_turno', 3);
        $this->assertDatabaseHas('tb_gastos_en_turno', [
            'turno_caja_id' => $turnoId,
            'tipo_gasto' => GastoEnTurno::TIPO_PAGO_PROVEEDOR,
            'proveedor_id' => $proveedor->id,
            'origen' => null,
        ]);
        $this->assertDatabaseHas('tb_gastos_en_turno', [
            'turno_caja_id' => $turnoId,
            'tipo_gasto' => GastoEnTurno::TIPO_GASTO_OPERATIVO,
            'proveedor_id' => null,
        ]);
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

    public function test_cannot_register_gasto_greater_than_fondo_plus_ventas_efectivo(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->seedCajaContext();

        $categoria = $negocio->categoriaProductos()->create(['name' => 'Bebidas']);
        $producto = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Agua',
            'price' => 1500,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($staff);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 2000,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Efectivo',
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 1500]],
        ])->assertCreated();

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Retiro de efectivo',
            'descripcion' => 'Excede caja',
            'monto' => 3500.01,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Retiro de efectivo',
            'descripcion' => 'Tope de caja',
            'monto' => 3500,
        ])->assertCreated();

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Gasto operativo',
            'descripcion' => 'Ya no hay efectivo',
            'monto' => 0.01,
        ])->assertStatus(422);
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
        $this->setStaffPermissions($staff, [
            'corteCaja' => false,
            'corteCajaCajera' => true,
        ]);

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 100,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 100,
        ])->assertOk();

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Gasto operativo',
            'descripcion' => 'Ya cerrado',
            'monto' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes registrar gastos en un turno cerrado.');
    }

    public function test_encargado_and_gerencia_can_register_gastos_after_cajera_closed(): void
    {
        [$user, $negocio, $sucursal, $cajera] = $this->seedCajaContext();
        $this->setStaffPermissions($cajera, [
            'corteCaja' => false,
            'corteCajaCajera' => true,
        ]);

        $encargado = $this->createStaffWithPermissions(
            $user,
            $negocio,
            $sucursal,
            'Encargado sucursal',
            'maria.encargada',
            'EMP-ENC',
            ['corteCaja' => true],
        );
        $gerente = $this->createStaffWithPermissions(
            $user,
            $negocio,
            $sucursal,
            'Gerente administrativo',
            'luis.gerencia',
            'EMP-GER-G',
            ['corteCajaGerenteAdmo' => true],
        );

        Sanctum::actingAs($cajera);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 200,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 200,
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.status', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_ABIERTO)
            ->assertJsonPath('data.turno.status_gerencia', TurnoCaja::STATUS_ABIERTO);

        Sanctum::actingAs($encargado);
        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Gasto operativo',
            'descripcion' => 'Compra de servilletas',
            'monto' => 50,
        ])
            ->assertCreated()
            ->assertJsonPath('data.gasto.descripcion', 'Compra de servilletas')
            ->assertJsonPath('data.gasto.monto', '50.00')
            ->assertJsonPath('data.turno.total_gastos_operativos', '50.00');

        $this->assertDatabaseHas('tb_turnos_cajas_cortes', [
            'turno_caja_id' => $turnoId,
            'tipo_corte' => TurnoCajaCorte::TIPO_CIERRE,
            'total_gastos_operativos' => 50,
        ]);

        Sanctum::actingAs($gerente);
        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Gasto operativo',
            'descripcion' => 'Gasto gerencia',
            'monto' => 20,
        ])
            ->assertCreated()
            ->assertJsonPath('data.turno.total_gastos_operativos', '70.00');

        Sanctum::actingAs($user);
        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Retiro de efectivo',
            'descripcion' => 'Gasto dueño en validación',
            'monto' => 10,
        ])
            ->assertCreated()
            ->assertJsonPath('data.turno.total_retiros_efectivo', '10.00');
    }

    public function test_encargado_cannot_register_gasto_after_status_administrador_closed(): void
    {
        [$user, $negocio, $sucursal, $cajera] = $this->seedCajaContext();
        $this->setStaffPermissions($cajera, [
            'corteCaja' => false,
            'corteCajaCajera' => true,
        ]);

        $encargado = $this->createStaffWithPermissions(
            $user,
            $negocio,
            $sucursal,
            'Encargado sucursal',
            'pedro.encargado',
            'EMP-ENC-2',
            ['corteCaja' => true],
        );
        $gerente = $this->createStaffWithPermissions(
            $user,
            $negocio,
            $sucursal,
            'Gerente administrativo',
            'sofia.gerencia',
            'EMP-GER-2',
            ['corteCajaGerenteAdmo' => true],
        );

        Sanctum::actingAs($cajera);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 150,
        ])->assertCreated()->json('data.turno.id');
        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 150,
        ])->assertOk();

        Sanctum::actingAs($encargado);
        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 150,
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turno.status_gerencia', TurnoCaja::STATUS_ABIERTO);

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Gasto operativo',
            'descripcion' => 'Encargado ya cerró',
            'monto' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes registrar gastos. El corte del encargado de sucursal ya está cerrado.');

        Sanctum::actingAs($gerente);
        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Gasto operativo',
            'descripcion' => 'Gerencia aún abierta',
            'monto' => 15,
        ])
            ->assertCreated()
            ->assertJsonPath('data.gasto.monto', '15.00')
            ->assertJsonPath('data.turno.total_gastos_operativos', '15.00');

        TurnoCaja::query()->whereKey($turnoId)->update([
            'status_gerencia' => TurnoCaja::STATUS_CERRADO,
        ]);

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Gasto operativo',
            'descripcion' => 'Gerencia ya cerró',
            'monto' => 5,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes registrar gastos. La validación gerencial ya está cerrada.');
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
        $proveedor = $this->createProveedor($user, $negocio);

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 200,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'id_proveedor' => $proveedor->id,
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

    public function test_gastos_de_cajera_y_encargada_restan_de_venta_y_no_de_cuenta_contable(): void
    {
        [$user, $negocio, $sucursal, $cajera] = $this->seedCajaContext();
        $this->setStaffPermissions($cajera, [
            'corteCaja' => false,
            'corteCajaCajera' => true,
        ]);

        $encargado = $this->createStaffWithPermissions(
            $user,
            $negocio,
            $sucursal,
            'Encargado sucursal',
            'maria.abastos',
            'EMP-ENC-AB',
            ['corteCaja' => true],
        );
        $proveedor = $this->createProveedor($user, $negocio);
        $categoria = $negocio->categoriaProductos()->create(['name' => 'General']);
        $producto = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'venta',
            'price' => 100,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Cuenta maestra',
            'saldo' => 26310,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subcuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'ABASTOS',
            'saldo' => 1900,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($cajera);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 1900,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Público en general',
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 10, 'precio' => 100]],
        ])->assertCreated();

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'proveedor_id' => $proveedor->id,
            'descripcion' => 'pago 100',
            'monto' => 100,
        ])->assertCreated();

        $this->getJson("/api/turnos-caja/{$turnoId}/preview")
            ->assertOk()
            ->assertJsonPath('data.preview.total_ventas_efectivo', 1000)
            ->assertJsonPath('data.preview.efectivo_esperado', 1000)
            ->assertJsonPath('data.preview.efectivo_esperado_ajustado', 900)
            ->assertJsonPath('data.preview.tramo_actual.efectivo_esperado', 900);

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 900,
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.status', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_ABIERTO)
            ->assertJsonPath('data.turno.efectivo_esperado', '1000.00')
            ->assertJsonPath('data.turno.efectivo_esperado_ajustado', '900.00')
            ->assertJsonPath('data.turno.diferencia', '0.00');

        $this->getJson("/api/turnos-caja/{$turnoId}/cortes")
            ->assertOk()
            ->assertJsonPath('data.cortes.0.total_ventas_efectivo', '1000.00')
            ->assertJsonPath('data.cortes.0.total_pagos_proveedores', '100.00')
            ->assertJsonPath('data.cortes.0.efectivo_esperado', '900.00')
            ->assertJsonPath('data.cortes.0.efectivo_real_cajera', '900.00')
            ->assertJsonPath('data.cortes.0.diferencia', '0.00');

        Sanctum::actingAs($encargado);
        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'proveedor_id' => $proveedor->id,
            'descripcion' => 'pago 100 encargada',
            'monto' => 100,
        ])->assertCreated();

        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 800,
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turno.total_pagos_proveedores', '200.00')
            ->assertJsonPath('data.turno.efectivo_esperado', '1000.00')
            ->assertJsonPath('data.turno.efectivo_esperado_ajustado', '800.00')
            ->assertJsonPath('data.turno.efectivo_real', '800.00')
            ->assertJsonPath('data.turno.diferencia', '0.00');

        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $subcuenta->id,
            'saldo' => 1900,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $maestra->id,
            'saldo' => 26310,
        ]);
        $this->assertDatabaseCount('mae_cuenta_conta_suc_detalle', 0);
    }

    public function test_pago_con_deposito_acumula_total_y_descuenta_cuenta_sin_restar_caja(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->seedCajaContext();
        $proveedor = $this->createProveedor($user, $negocio);
        $categoria = $negocio->categoriaProductos()->create(['name' => 'General']);
        $producto = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'venta',
            'price' => 100,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subcuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'ABASTOS',
            'saldo' => 2000,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($staff);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 500,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Público en general',
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $producto->id, 'cantidad' => 1, 'precio' => 100]],
        ])->assertCreated();

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'descuento_a' => 'depositos',
            'proveedor_id' => $proveedor->id,
            'descripcion' => 'Pago a distribuidor de refrescos',
            'monto' => 700,
        ])
            ->assertCreated()
            ->assertJsonPath('data.gasto.tipo_gasto', GastoEnTurno::TIPO_PAGO_PROVEEDOR)
            ->assertJsonPath('data.gasto.origen', GastoEnTurno::ORIGEN_DEPOSITO)
            ->assertJsonPath('data.gasto.origen_label', 'Descuento a depósitos')
            ->assertJsonPath('data.turno.total_pagos_con_deposito', '700.00')
            ->assertJsonPath('data.turno.total_pagos_proveedores', '0.00');

        $this->assertDatabaseHas('tb_turnos_cajas', [
            'id' => $turnoId,
            'total_pagos_con_deposito' => 700,
            'total_pagos_proveedores' => 0,
        ]);
        $this->assertDatabaseHas('tb_gastos_en_turno', [
            'turno_caja_id' => $turnoId,
            'origen' => GastoEnTurno::ORIGEN_DEPOSITO,
            'monto' => 700,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $subcuenta->id,
            'saldo' => 1300,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc_detalle', [
            'cuenta_origen_id' => $subcuenta->id,
            'tipo_movimiento' => MaeCuentaContaSucDetalle::TIPO_PAGO_PROVEEDOR,
            'monto_movimiento' => 700,
        ]);

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'origen' => 'deposito',
            'proveedor_id' => $proveedor->id,
            'descripcion' => 'Excede depósito',
            'monto' => 1300.01,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'proveedor_id' => $proveedor->id,
            'descripcion' => 'Pago con venta',
            'monto' => 80,
        ])
            ->assertCreated()
            ->assertJsonPath('data.gasto.origen', null)
            ->assertJsonPath('data.turno.total_pagos_proveedores', '80.00')
            ->assertJsonPath('data.turno.total_pagos_con_deposito', '700.00');

        $this->getJson("/api/turnos-caja/{$turnoId}/gastos")
            ->assertOk()
            ->assertJsonPath('data.totales.pago_proveedor', 80)
            ->assertJsonPath('data.totales.pagos_con_deposito', 700)
            ->assertJsonPath('data.totales.total', 80);

        $this->getJson("/api/turnos-caja/{$turnoId}/preview")
            ->assertOk()
            ->assertJsonPath('data.preview.total_pagos_con_deposito', 700)
            ->assertJsonPath('data.preview.total_pagos_proveedores', 80)
            ->assertJsonPath('data.preview.efectivo_esperado_ajustado', 20);
    }

    public function test_pago_con_deposito_requiere_cuenta_de_sucursal(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->seedCajaContext();
        $proveedor = $this->createProveedor($user, $negocio);

        Sanctum::actingAs($staff);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 500,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'origen' => 'deposito',
            'proveedor_id' => $proveedor->id,
            'descripcion' => 'Sin cuenta de depósito',
            'monto' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'No hay cuenta de depósito activa para esta sucursal.');
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

    public function test_pago_proveedor_requires_active_proveedor_of_negocio(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->seedCajaContext();
        $proveedor = $this->createProveedor($user, $negocio);

        $baja = $negocio->proveedores()->create([
            'name' => 'Proveedor Baja',
            'status' => Proveedor::STATUS_BAJA,
            'created_by' => $user->id,
        ]);

        $other = User::factory()->create();
        $otherNegocio = $other->negocio()->create([
            'name' => 'Otro Negocio',
            'phone' => '5522222222',
            'needs_invoice' => false,
        ]);
        $ajeno = $otherNegocio->proveedores()->create([
            'name' => 'Proveedor Ajeno',
            'status' => Proveedor::STATUS_ACTIVO,
            'created_by' => $other->id,
        ]);

        Sanctum::actingAs($staff);

        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 50,
        ])->assertCreated()->json('data.turno.id');

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'descripcion' => 'Sin proveedor',
            'monto' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['proveedor_id']);

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'proveedor_id' => $baja->id,
            'descripcion' => 'Proveedor de baja',
            'monto' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['proveedor_id']);

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'proveedor_id' => $ajeno->id,
            'descripcion' => 'Proveedor de otro negocio',
            'monto' => 10,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['proveedor_id']);

        $this->postJson("/api/turnos-caja/{$turnoId}/gastos", [
            'tipo' => 'Pago proveedor',
            'proveedor' => $proveedor->id,
            'descripcion' => 'Pago válido',
            'monto' => 12,
        ])
            ->assertCreated()
            ->assertJsonPath('data.gasto.proveedor_id', $proveedor->id);
    }

    /**
     * @return array{0: User, 1: Negocio, 2: Sucursal, 3: Staff}
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

    private function createProveedor(User $user, Negocio $negocio): Proveedor
    {
        return $negocio->proveedores()->create([
            'name' => 'Distribuidora Norte',
            'status' => Proveedor::STATUS_ACTIVO,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @param  array<string, bool>  $overrides
     */
    private function setStaffPermissions(Staff $staff, array $overrides): void
    {
        $staff->loadMissing('role');
        $permissions = Role::defaultPermissions();
        foreach ($overrides as $key => $value) {
            $permissions[$key] = $value;
        }
        $staff->role->update(['permissions' => $permissions]);
        $staff->unsetRelation('role');
    }

    /**
     * @param  array<string, bool>  $permissionOverrides
     */
    private function createStaffWithPermissions(
        User $user,
        Negocio $negocio,
        Sucursal $sucursal,
        string $roleName,
        string $username,
        string $employeeNumber,
        array $permissionOverrides,
    ): Staff {
        $permissions = Role::defaultPermissions();
        foreach ($permissionOverrides as $key => $value) {
            $permissions[$key] = $value;
        }

        $role = $negocio->roles()->create([
            'name' => $roleName,
            'permissions' => $permissions,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $empleado = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'first_name' => $roleName,
            'paternal_surname' => 'Test',
            'employee_number' => $employeeNumber,
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $negocio->staff()->create([
            'username' => $username,
            'password' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'empleado_id' => $empleado->id,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
