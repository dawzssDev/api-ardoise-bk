<?php

namespace Tests\Feature;

use App\Models\GastoEnTurno;
use App\Models\MaeCuentaContaSuc;
use App\Models\MaeCuentaContaSucDetalle;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\TurnoCaja;
use App\Models\TurnoCajaCorte;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MaeCuentaContaSucTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_cuenta_maestra_without_sucursal(): void
    {
        [$user] = $this->actingWithNegocio();

        $this->postJson('/api/cuentas-contables', [
            'tipoCuenta' => 'maestra',
            'tituloCuenta' => 'Cuenta general',
            'descripcionCuenta' => 'Cuenta maestra del negocio',
            'SucursaliD' => 99,
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cuenta.tipoCuenta', MaeCuentaContaSuc::TIPO_MAESTRA)
            ->assertJsonPath('data.cuenta.sucursal_id', null)
            ->assertJsonPath('data.cuenta.tituloCuenta', 'Cuenta general')
            ->assertJsonPath('data.cuenta.status', 1)
            ->assertJsonPath('data.cuenta.delete', 0);

        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'negocio_id' => $user->negocio->id,
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'sucursal_id' => null,
            'titulo_cuenta' => 'Cuenta general',
            'deleted' => 0,
        ]);
    }

    public function test_user_can_create_subcuenta_linked_to_sucursal(): void
    {
        [$user, $negocio, $sucursal] = $this->actingWithNegocio();

        $this->postJson('/api/cuentas-contables', [
            'tipo_cuenta' => 'subcuenta',
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'Caja sucursal centro',
        ])
            ->assertCreated()
            ->assertJsonPath('data.cuenta.tipo_cuenta', MaeCuentaContaSuc::TIPO_SUBCUENTA)
            ->assertJsonPath('data.cuenta.sucursal_id', $sucursal->id)
            ->assertJsonPath('data.cuenta.sucursal.name', 'Centro');

        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'negocio_id' => $negocio->id,
            'sucursal_id' => $sucursal->id,
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
        ]);
        $this->assertSame($user->id, $user->id);
    }

    public function test_subcuenta_requires_sucursal_of_negocio(): void
    {
        [, $negocio, $sucursal] = $this->actingWithNegocio();

        $this->postJson('/api/cuentas-contables', [
            'tipoCuenta' => 'subcuenta',
            'tituloCuenta' => 'Sin sucursal',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sucursal_id']);

        $other = User::factory()->create();
        $otherNegocio = $other->negocio()->create([
            'name' => 'Otro',
            'phone' => '6671111111',
            'needs_invoice' => false,
        ]);
        $ajena = $otherNegocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Ajena',
            'is_active' => true,
        ]);

        $this->postJson('/api/cuentas-contables', [
            'tipo_cuenta' => 'subcuenta',
            'sucursal_id' => $ajena->id,
            'titulo_cuenta' => 'Sucursal ajena',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sucursal_id']);

        $this->assertSame($negocio->id, $negocio->id);
        $this->assertSame($sucursal->negocio_id, $negocio->id);
    }

    public function test_user_can_list_update_and_soft_delete_cuenta(): void
    {
        [$user, $negocio] = $this->actingWithNegocio();

        $cuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Original',
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->getJson('/api/cuentas-contables')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.cuentas.0.id', $cuenta->id);

        $this->getJson('/api/cuentas-contables/'.$cuenta->id)
            ->assertOk()
            ->assertJsonPath('data.cuenta.titulo_cuenta', 'Original');

        $this->putJson('/api/cuentas-contables/'.$cuenta->id, [
            'tituloCuenta' => 'Actualizada',
        ])
            ->assertOk()
            ->assertJsonPath('data.cuenta.tituloCuenta', 'Actualizada');

        $this->deleteJson('/api/cuentas-contables/'.$cuenta->id)
            ->assertOk()
            ->assertJsonPath('data.cuenta.delete', 1)
            ->assertJsonPath('data.cuenta.status', 0);

        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $cuenta->id,
            'deleted' => 1,
            'status' => 0,
        ]);

        $this->getJson('/api/cuentas-contables/'.$cuenta->id)->assertNotFound();
    }

    public function test_user_can_register_and_list_movimientos(): void
    {
        [$user, $negocio, $sucursal] = $this->actingWithNegocio();

        $cuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Maestra',
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $subcuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'Caja sucursal',
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->postJson("/api/cuentas-contables/{$cuenta->id}/detalles", [
            'tipoMovimiento' => 'Deposito',
            'descripcionMovimiento' => 'Depósito inicial',
            'montoMovimiento' => 500.5,
        ])
            ->assertCreated()
            ->assertJsonPath('data.detalle.tipoMovimiento', MaeCuentaContaSucDetalle::TIPO_DEPOSITO)
            ->assertJsonPath('data.detalle.idMaeCuentaContaSuc', $cuenta->id)
            ->assertJsonPath('data.detalle.cuentaOrigen', null)
            ->assertJsonPath('data.detalle.cuentaDestino', $cuenta->id)
            ->assertJsonPath('data.detalle.montoMovimiento', '500.50')
            ->assertJsonPath('data.detalle.status', MaeCuentaContaSucDetalle::STATUS_ACEPTADO)
            ->assertJsonPath('data.detalle.delete', 0);

        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $cuenta->id,
            'saldo' => 500.50,
        ]);

        $transfer = $this->postJson('/api/cuentas-contables-detalles', [
            'idMaeCuentaContaSuc' => $cuenta->id,
            'tipo_movimiento' => 'transferencia',
            'descripcion_movimiento' => 'Traspaso a sucursal',
            'monto' => 120,
            'cuentaOrigen' => $cuenta->id,
            'cuentaDestino' => $subcuenta->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.detalle.tipo_movimiento', MaeCuentaContaSucDetalle::TIPO_TRANSFERENCIA)
            ->assertJsonPath('data.detalle.cuenta_origen_id', $cuenta->id)
            ->assertJsonPath('data.detalle.cuenta_destino_id', $subcuenta->id)
            ->assertJsonPath('data.detalle.monto_movimiento', '120.00')
            ->assertJsonPath('data.detalle.status', MaeCuentaContaSucDetalle::STATUS_PENDIENTE)
            ->assertJsonPath('data.detalle.status_label', 'pendiente');

        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $cuenta->id,
            'saldo' => 380.50,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $subcuenta->id,
            'saldo' => 0,
        ]);

        $this->getJson("/api/cuentas-contables/{$cuenta->id}/detalles")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        $detalleId = $transfer->json('data.detalle.id');

        $this->putJson('/api/cuentas-contables-detalles/'.$detalleId, [
            'descripcion' => 'Descripción editada',
        ])
            ->assertOk()
            ->assertJsonPath('data.detalle.descripcion_movimiento', 'Descripción editada');

        $this->deleteJson('/api/cuentas-contables-detalles/'.$detalleId)
            ->assertStatus(422)
            ->assertJsonPath('message', 'No puedes eliminar una solicitud pendiente. El encargado de sucursal debe aceptarla o rechazarla.');
    }

    public function test_gerente_can_register_direct_gasto_on_selected_account(): void
    {
        [$user, $negocio] = $this->actingWithNegocio();

        $cuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Cuenta maestra',
            'saldo' => 14389,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->postJson("/api/cuentas-contables/{$cuenta->id}/detalles", [
            'tipo' => 'Gasto operativo',
            'descripcion' => 'Pago contador 1000 mes SEP',
            'monto' => 1000,
            'cuentaOrigen' => $cuenta->id,
            'cuentaDestino' => $cuenta->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.detalle.tipo_movimiento', MaeCuentaContaSucDetalle::TIPO_GASTO_OPERATIVO)
            ->assertJsonPath('data.detalle.tipo_solicitud', 'gasto')
            ->assertJsonPath('data.detalle.sentido', 'salida')
            ->assertJsonPath('data.detalle.es_salida', true)
            ->assertJsonPath('data.detalle.es_entrada', false)
            ->assertJsonPath('data.detalle.cuenta_origen_id', $cuenta->id)
            ->assertJsonPath('data.detalle.cuenta_destino_id', null)
            ->assertJsonPath('data.detalle.monto_movimiento', '1000.00')
            ->assertJsonPath('data.detalle.status', MaeCuentaContaSucDetalle::STATUS_ACEPTADO);

        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $cuenta->id,
            'saldo' => 13389,
        ]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc_detalle', [
            'mae_cuenta_conta_suc_id' => $cuenta->id,
            'tipo_movimiento' => MaeCuentaContaSucDetalle::TIPO_GASTO_OPERATIVO,
            'descripcion_movimiento' => 'Pago contador 1000 mes SEP',
            'monto_movimiento' => 1000,
            'cuenta_destino_id' => null,
        ]);
    }

    public function test_capturar_gasto_works_when_front_sends_transferencia_on_same_account(): void
    {
        [$user, $negocio] = $this->actingWithNegocio();

        $cuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'ABASTOS',
            'saldo' => 2000,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->postJson("/api/cuentas-contables/{$cuenta->id}/detalles", [
            'tipoMovimiento' => 'transferencia',
            'tipo' => 'Gasto operativo',
            'descripcion' => 'abogado',
            'monto' => 1500,
            'cuentaOrigen' => $cuenta->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.detalle.tipo_movimiento', MaeCuentaContaSucDetalle::TIPO_GASTO_OPERATIVO)
            ->assertJsonPath('data.detalle.sentido', 'salida')
            ->assertJsonPath('data.detalle.es_salida', true)
            ->assertJsonPath('data.detalle.cuenta_destino_id', null)
            ->assertJsonPath('data.detalle.monto_movimiento', '1500.00');

        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $cuenta->id,
            'saldo' => 500,
        ]);
    }

    public function test_direct_gasto_rejects_insufficient_balance(): void
    {
        [$user, $negocio] = $this->actingWithNegocio();

        $cuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Cuenta maestra',
            'saldo' => 100,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $this->postJson('/api/cuentas-contables-detalles', [
            'idMaeCuentaContaSuc' => $cuenta->id,
            'tipo_gasto' => 'Pago proveedor',
            'descripcion_movimiento' => 'Factura',
            'monto_movimiento' => 150,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Saldo insuficiente en la cuenta origen.');

        $this->assertDatabaseHas('mae_cuenta_conta_suc', [
            'id' => $cuenta->id,
            'saldo' => 100,
        ]);
    }

    public function test_encargado_can_accept_transfer_from_maestra_to_subcuenta(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->actingWithStaffSucursal();

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Maestra',
            'saldo' => 200,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subcuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'Caja sucursal',
            'saldo' => 0,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);
        $detalleId = $this->postJson('/api/cuentas-contables-detalles', [
            'idMaeCuentaContaSuc' => $maestra->id,
            'tipoMovimiento' => 'transferencia',
            'descripcionMovimiento' => 'Envío a sucursal',
            'montoMovimiento' => 80,
            'cuentaOrigen' => $maestra->id,
            'cuentaDestino' => $subcuenta->id,
        ])->assertCreated()->json('data.detalle.id');

        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $maestra->id, 'saldo' => 120]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $subcuenta->id, 'saldo' => 0]);

        Sanctum::actingAs($staff);
        $this->postJson("/api/cuentas-contables-detalles/{$detalleId}/aceptar")
            ->assertOk()
            ->assertJsonPath('data.detalle.status', MaeCuentaContaSucDetalle::STATUS_ACEPTADO)
            ->assertJsonPath('data.detalle.cuenta_destino.saldo', '80.00');

        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $maestra->id, 'saldo' => 120]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $subcuenta->id, 'saldo' => 80]);
    }

    public function test_reject_returns_money_to_cuenta_maestra(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->actingWithStaffSucursal();

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Maestra',
            'saldo' => 200,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subcuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'Caja sucursal',
            'saldo' => 10,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);
        $detalleId = $this->postJson('/api/cuentas-contables-detalles', [
            'idMaeCuentaContaSuc' => $maestra->id,
            'tipoMovimiento' => 'transferencia',
            'descripcionMovimiento' => 'Envío a sucursal',
            'montoMovimiento' => 50,
            'cuentaOrigen' => $maestra->id,
            'cuentaDestino' => $subcuenta->id,
        ])->assertCreated()->json('data.detalle.id');

        Sanctum::actingAs($staff);
        $this->postJson("/api/cuentas-contables-detalles/{$detalleId}/rechazar")
            ->assertOk()
            ->assertJsonPath('data.detalle.status', MaeCuentaContaSucDetalle::STATUS_RECHAZADO)
            ->assertJsonPath('data.detalle.status_label', 'rechazado');

        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $maestra->id, 'saldo' => 200]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $subcuenta->id, 'saldo' => 10]);
    }

    public function test_staff_from_other_sucursal_cannot_accept_transfer(): void
    {
        [$user, $negocio, $sucursal] = $this->actingWithNegocio();
        $otraSucursal = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Norte',
            'is_active' => true,
        ]);
        [, , , $otroStaff] = $this->staffForSucursal($user, $negocio, $otraSucursal, 'otro.staff');

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Maestra',
            'saldo' => 100,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subcuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'Caja centro',
            'saldo' => 0,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);
        $detalleId = $this->postJson('/api/cuentas-contables-detalles', [
            'idMaeCuentaContaSuc' => $maestra->id,
            'tipoMovimiento' => 'transferencia',
            'descripcionMovimiento' => 'Envío',
            'montoMovimiento' => 20,
            'cuentaOrigen' => $maestra->id,
            'cuentaDestino' => $subcuenta->id,
        ])->assertCreated()->json('data.detalle.id');

        Sanctum::actingAs($otroStaff);
        $this->postJson("/api/cuentas-contables-detalles/{$detalleId}/aceptar")
            ->assertForbidden();
    }

    public function test_list_pendientes_by_sucursal_for_staff_and_master(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->actingWithStaffSucursal();
        $otraSucursal = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Norte',
            'is_active' => true,
        ]);

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Maestra',
            'saldo' => 300,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subCentro = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'Caja centro',
            'saldo' => 0,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subNorte = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $otraSucursal->id,
            'titulo_cuenta' => 'Caja norte',
            'saldo' => 0,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);
        $pendienteCentro = $this->postJson('/api/cuentas-contables-detalles', [
            'idMaeCuentaContaSuc' => $maestra->id,
            'tipoMovimiento' => 'transferencia',
            'descripcionMovimiento' => 'Para centro',
            'montoMovimiento' => 40,
            'cuentaOrigen' => $maestra->id,
            'cuentaDestino' => $subCentro->id,
        ])->assertCreated()->json('data.detalle.id');

        $this->postJson('/api/cuentas-contables-detalles', [
            'idMaeCuentaContaSuc' => $maestra->id,
            'tipoMovimiento' => 'transferencia',
            'descripcionMovimiento' => 'Para norte',
            'montoMovimiento' => 25,
            'cuentaOrigen' => $maestra->id,
            'cuentaDestino' => $subNorte->id,
        ])->assertCreated();

        Sanctum::actingAs($staff);
        $this->getJson('/api/cuentas-contables-detalles/pendientes')
            ->assertOk()
            ->assertJsonPath('data.sucursal_id', $sucursal->id)
            ->assertJsonPath('data.status', MaeCuentaContaSucDetalle::STATUS_PENDIENTE)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.detalles.0.id', $pendienteCentro);

        Sanctum::actingAs($user);
        $this->getJson('/api/cuentas-contables-detalles/pendientes')
            ->assertStatus(422);

        $this->getJson('/api/cuentas-contables-detalles/pendientes?sucursal_id='.$otraSucursal->id)
            ->assertOk()
            ->assertJsonPath('data.sucursal_id', $otraSucursal->id)
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.detalles.0.descripcion_movimiento', 'Para norte');
    }

    public function test_subcuenta_to_maestra_creates_pending_retiro_authorized_by_sucursal(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->actingWithStaffSucursal();

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Maestra',
            'saldo' => 100,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subcuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'Caja sucursal',
            'saldo' => 150,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);
        $detalleId = $this->postJson('/api/cuentas-contables-detalles', [
            'idMaeCuentaContaSuc' => $subcuenta->id,
            'tipoMovimiento' => 'transferencia',
            'descripcionMovimiento' => 'Envío a matriz',
            'montoMovimiento' => 60,
            'cuentaOrigen' => $subcuenta->id,
            'cuentaDestino' => $maestra->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.detalle.tipo_movimiento', MaeCuentaContaSucDetalle::TIPO_RETIRO)
            ->assertJsonPath('data.detalle.tipo_solicitud', 'retiro')
            ->assertJsonPath('data.detalle.status', MaeCuentaContaSucDetalle::STATUS_PENDIENTE)
            ->json('data.detalle.id');

        // Al crear no se mueve saldo
        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $subcuenta->id, 'saldo' => 150]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $maestra->id, 'saldo' => 100]);

        Sanctum::actingAs($staff);
        $this->getJson('/api/cuentas-contables-detalles/pendientes')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.detalles.0.id', $detalleId)
            ->assertJsonPath('data.detalles.0.tipo_movimiento', MaeCuentaContaSucDetalle::TIPO_RETIRO);

        $this->postJson("/api/cuentas-contables-detalles/{$detalleId}/aceptar")
            ->assertOk()
            ->assertJsonPath('data.detalle.status', MaeCuentaContaSucDetalle::STATUS_ACEPTADO);

        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $subcuenta->id, 'saldo' => 90]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $maestra->id, 'saldo' => 160]);
    }

    public function test_accepting_deposito_and_retiro_saves_them_on_turno_and_corte_after_cajera_closed(): void
    {
        [$user, $negocio, $sucursal, $encargado] = $this->actingWithStaffSucursal();

        $permissions = Role::defaultPermissions();
        $permissions['corteCaja'] = false;
        $permissions['corteCajaCajera'] = true;
        $roleCajera = $negocio->roles()->create([
            'name' => 'Cajera corte',
            'permissions' => $permissions,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $empleadoCajera = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $roleCajera->id,
            'first_name' => 'Ana',
            'paternal_surname' => 'Caja',
            'employee_number' => 'EMP-CAJ-CC',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $cajera = $negocio->staff()->create([
            'username' => 'ana.corte.conta',
            'password' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $roleCajera->id,
            'empleado_id' => $empleadoCajera->id,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Maestra',
            'saldo' => 500,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subcuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'Caja sucursal',
            'saldo' => 80,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($cajera);
        $turnoId = $this->postJson('/api/turnos-caja/abrir', [
            'fondo_inicial' => 200,
        ])->assertCreated()->json('data.turno.id');
        $this->postJson("/api/turnos-caja/{$turnoId}/cerrar", [
            'efectivo_real' => 200,
        ])
            ->assertOk()
            ->assertJsonPath('data.turno.status', TurnoCaja::STATUS_CERRADO)
            ->assertJsonPath('data.turno.status_administrador', TurnoCaja::STATUS_ABIERTO);

        Sanctum::actingAs($user);
        $depositoId = $this->postJson('/api/cuentas-contables-detalles', [
            'idMaeCuentaContaSuc' => $maestra->id,
            'tipoMovimiento' => 'transferencia',
            'descripcionMovimiento' => 'Depósito a sucursal',
            'montoMovimiento' => 120,
            'cuentaOrigen' => $maestra->id,
            'cuentaDestino' => $subcuenta->id,
        ])->assertCreated()->json('data.detalle.id');

        $retiroId = $this->postJson('/api/cuentas-contables-detalles', [
            'idMaeCuentaContaSuc' => $subcuenta->id,
            'tipoMovimiento' => 'retiro',
            'descripcionMovimiento' => 'Retiro a matriz',
            'montoMovimiento' => 40,
            'cuentaOrigen' => $subcuenta->id,
            'cuentaDestino' => $maestra->id,
        ])->assertCreated()->json('data.detalle.id');

        Sanctum::actingAs($encargado);
        $this->postJson("/api/cuentas-contables-detalles/{$depositoId}/aceptar")
            ->assertOk()
            ->assertJsonPath('data.detalle.status', MaeCuentaContaSucDetalle::STATUS_ACEPTADO);

        $this->assertDatabaseHas('tb_deposito_efectivo_en_turno', [
            'turno_caja_id' => $turnoId,
            'descripcion' => 'Depósito a sucursal',
            'monto' => 120,
        ]);
        $this->assertDatabaseHas('tb_turnos_cajas', [
            'id' => $turnoId,
            'total_depositos_efectivo' => 120,
        ]);
        $this->assertDatabaseHas('tb_turnos_cajas_cortes', [
            'turno_caja_id' => $turnoId,
            'tipo_corte' => TurnoCajaCorte::TIPO_CIERRE,
            'total_depositos_efectivo' => 120,
        ]);

        $this->postJson("/api/cuentas-contables-detalles/{$retiroId}/aceptar")
            ->assertOk()
            ->assertJsonPath('data.detalle.status', MaeCuentaContaSucDetalle::STATUS_ACEPTADO);

        $this->assertDatabaseHas('tb_gastos_en_turno', [
            'turno_caja_id' => $turnoId,
            'tipo_gasto' => GastoEnTurno::TIPO_RETIRO_EFECTIVO,
            'descripcion' => 'Retiro a matriz',
            'monto' => 40,
        ]);
        $this->assertDatabaseHas('tb_turnos_cajas', [
            'id' => $turnoId,
            'total_retiros_efectivo' => 40,
            'total_depositos_efectivo' => 120,
        ]);
        $this->assertDatabaseHas('tb_turnos_cajas_cortes', [
            'turno_caja_id' => $turnoId,
            'tipo_corte' => TurnoCajaCorte::TIPO_CIERRE,
            'total_retiros_efectivo' => 40,
            'total_depositos_efectivo' => 120,
        ]);
    }

    public function test_reject_retiro_does_not_change_balances(): void
    {
        [$user, $negocio, $sucursal, $staff] = $this->actingWithStaffSucursal();

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Maestra',
            'saldo' => 50,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subcuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'Caja sucursal',
            'saldo' => 80,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);
        $detalleId = $this->postJson('/api/cuentas-contables-detalles', [
            'idMaeCuentaContaSuc' => $subcuenta->id,
            'tipoMovimiento' => 'retiro',
            'descripcionMovimiento' => 'Retiro a matriz',
            'montoMovimiento' => 30,
            'cuentaOrigen' => $subcuenta->id,
            'cuentaDestino' => $maestra->id,
        ])->assertCreated()->json('data.detalle.id');

        Sanctum::actingAs($staff);
        $this->postJson("/api/cuentas-contables-detalles/{$detalleId}/rechazar")
            ->assertOk()
            ->assertJsonPath('data.detalle.status', MaeCuentaContaSucDetalle::STATUS_RECHAZADO);

        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $subcuenta->id, 'saldo' => 80]);
        $this->assertDatabaseHas('mae_cuenta_conta_suc', ['id' => $maestra->id, 'saldo' => 50]);
    }

    public function test_staff_from_other_sucursal_cannot_accept_retiro(): void
    {
        [$user, $negocio, $sucursal] = $this->actingWithNegocio();
        $otraSucursal = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Norte',
            'is_active' => true,
        ]);
        [, , , $otroStaff] = $this->staffForSucursal($user, $negocio, $otraSucursal, 'otro.retiro');

        $maestra = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_MAESTRA,
            'titulo_cuenta' => 'Maestra',
            'saldo' => 10,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $subcuenta = $negocio->cuentasContables()->create([
            'tipo_cuenta' => MaeCuentaContaSuc::TIPO_SUBCUENTA,
            'sucursal_id' => $sucursal->id,
            'titulo_cuenta' => 'Caja centro',
            'saldo' => 40,
            'status' => MaeCuentaContaSuc::STATUS_ACTIVO,
            'deleted' => MaeCuentaContaSuc::DELETED_NO,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);
        $detalleId = $this->postJson('/api/cuentas-contables-detalles', [
            'idMaeCuentaContaSuc' => $subcuenta->id,
            'tipoMovimiento' => 'transferencia',
            'descripcionMovimiento' => 'A matriz',
            'montoMovimiento' => 15,
            'cuentaOrigen' => $subcuenta->id,
            'cuentaDestino' => $maestra->id,
        ])->assertCreated()->json('data.detalle.id');

        Sanctum::actingAs($otroStaff);
        $this->postJson("/api/cuentas-contables-detalles/{$detalleId}/aceptar")
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: \App\Models\Negocio, 2: Sucursal}
     */
    private function actingWithNegocio(): array
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

        Sanctum::actingAs($user);

        return [$user->fresh(), $negocio, $sucursal];
    }

    /**
     * @return array{0: User, 1: \App\Models\Negocio, 2: Sucursal, 3: \App\Models\Staff}
     */
    private function actingWithStaffSucursal(): array
    {
        [$user, $negocio, $sucursal] = $this->actingWithNegocio();
        [, , , $staff] = $this->staffForSucursal($user, $negocio, $sucursal, 'encargado.centro');

        return [$user, $negocio, $sucursal, $staff];
    }

    /**
     * @return array{0: User, 1: \App\Models\Negocio, 2: Sucursal, 3: \App\Models\Staff}
     */
    private function staffForSucursal(User $user, \App\Models\Negocio $negocio, Sucursal $sucursal, string $username): array
    {
        $role = $negocio->roles()->create([
            'name' => 'Encargado '.$username,
            'permissions' => \App\Models\Role::defaultPermissions(),
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $empleado = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'first_name' => 'Encargado',
            'paternal_surname' => 'Sucursal',
            'employee_number' => 'EMP-'.$username,
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $staff = $negocio->staff()->create([
            'username' => $username,
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
