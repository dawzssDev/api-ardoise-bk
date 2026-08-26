<?php

namespace Tests\Feature;

use App\Models\MaeCuentaContaSuc;
use App\Models\MaeCuentaContaSucDetalle;
use App\Models\Sucursal;
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
            ->assertJsonPath('data.detalle.delete', 0);

        $this->postJson('/api/cuentas-contables-detalles', [
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
            ->assertJsonPath('data.detalle.monto_movimiento', '120.00');

        $this->getJson("/api/cuentas-contables/{$cuenta->id}/detalles")
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        $detalleId = $this->getJson('/api/cuentas-contables-detalles?idMaeCuentaContaSuc='.$cuenta->id)
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2)
            ->json('data.detalles.0.id');

        $this->putJson('/api/cuentas-contables-detalles/'.$detalleId, [
            'descripcion' => 'Descripción editada',
        ])
            ->assertOk()
            ->assertJsonPath('data.detalle.descripcion_movimiento', 'Descripción editada');

        $this->deleteJson('/api/cuentas-contables-detalles/'.$detalleId)
            ->assertOk()
            ->assertJsonPath('data.detalle.delete', 1);

        $this->assertDatabaseHas('mae_cuenta_conta_suc_detalle', [
            'id' => $detalleId,
            'deleted' => 1,
        ]);
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
}
