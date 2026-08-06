<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrdenTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_orden_with_detalles_from_pos(): void
    {
        [$user, $negocio, $sucursal, $esquite, $ramen] = $this->seedPosCatalog();

        Sanctum::actingAs($user);
        $this->abrirCaja($sucursal->id, 100);

        $response = $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Luis',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [
                [
                    'producto_id' => $esquite->id,
                    'cantidad' => 1,
                    'precio' => 50,
                ],
                [
                    'producto_id' => $ramen->id,
                    'cantidad' => 1,
                    'precio' => 70,
                    'observaciones' => 'Sin cebolla',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.orden.numero_orden', '000001')
            ->assertJsonPath('data.orden.nombre_cliente', 'Luis')
            ->assertJsonPath('data.orden.tipo_pago', 'efectivo')
            ->assertJsonPath('data.orden.total', '120.00')
            ->assertJsonPath('data.orden.estatus', Orden::STATUS_PAGADA)
            ->assertJsonPath('data.orden.staff_creo', null)
            ->assertJsonPath('data.orden.detalles.0.nombre_pedido', 'Esquite chico')
            ->assertJsonPath('data.orden.detalles.1.observaciones', 'Sin cebolla');

        $this->assertDatabaseHas('ordenes', [
            'negocio_id' => $negocio->id,
            'order_number' => 1,
            'customer_name' => 'Luis',
            'total' => 120.00,
            'created_by_staff_id' => null,
        ]);

        $this->assertDatabaseCount('orden_detalles', 2);
    }

    public function test_staff_is_tracked_on_create_and_kitchen_progress(): void
    {
        [$user, $negocio, $sucursal, $esquite] = $this->seedPosCatalog();

        $role = $negocio->roles()->create([
            'name' => 'Cajero',
            'permissions' => Role::defaultPermissions(),
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $empleadoPos = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'first_name' => 'Ana',
            'paternal_surname' => 'Pos',
            'employee_number' => 'EMP-POS',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $empleadoCocina = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'first_name' => 'Luis',
            'paternal_surname' => 'Cocina',
            'employee_number' => 'EMP-COC',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $staffPos = $negocio->staff()->create([
            'username' => 'ana.pos',
            'password' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'empleado_id' => $empleadoPos->id,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $staffCocina = $negocio->staff()->create([
            'username' => 'luis.cocina',
            'password' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'empleado_id' => $empleadoCocina->id,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($staffPos);
        $this->abrirCaja(null, 200);

        $create = $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Mesa 3',
            'tipo_pago' => 'efectivo',
            'detalles' => [
                ['producto_id' => $esquite->id, 'cantidad' => 1],
            ],
        ])->assertCreated();

        $ordenId = $create->json('data.orden.id');
        $detalleId = $create->json('data.orden.detalles.0.id');

        $create->assertJsonPath('data.orden.staff_creo.id', $staffPos->id)
            ->assertJsonPath('data.orden.staff_creo.username', 'ana.pos');

        Sanctum::actingAs($staffCocina);

        $this->putJson("/api/ordenes/{$ordenId}/detalles/{$detalleId}/status", [
            'estatus' => OrdenDetalle::STATUS_EN_PREPARACION,
        ])
            ->assertOk()
            ->assertJsonPath('data.detalle.staff_avanzo.id', $staffCocina->id)
            ->assertJsonPath('data.detalle.estatus', OrdenDetalle::STATUS_EN_PREPARACION);

        $this->putJson("/api/ordenes/{$ordenId}/detalles/{$detalleId}/status", [
            'estatus' => OrdenDetalle::STATUS_LISTO,
        ])
            ->assertOk()
            ->assertJsonPath('data.detalle.staff_finalizo.id', $staffCocina->id);

        $ordenReady = $this->getJson("/api/ordenes/{$ordenId}")
            ->assertOk()
            ->assertJsonPath('data.orden.estatus', Orden::STATUS_LISTA);

        $this->assertNotNull($ordenReady->json('data.orden.seconds_in_nuevo'));
        $this->assertNotNull($ordenReady->json('data.orden.seconds_in_preparacion'));
        $this->assertNotNull($ordenReady->json('data.orden.seconds_total_listo'));
        $this->assertNotNull($ordenReady->json('data.orden.listo_at'));

        $this->putJson("/api/ordenes/{$ordenId}/status", [
            'estatus' => Orden::STATUS_ENTREGADA,
        ])
            ->assertOk()
            ->assertJsonPath('data.orden.staff_avanzo.id', $staffCocina->id)
            ->assertJsonPath('data.orden.staff_finalizo.id', $staffCocina->id)
            ->assertJsonPath('data.orden.estatus', Orden::STATUS_ENTREGADA);

        $this->assertDatabaseHas('ordenes', [
            'id' => $ordenId,
            'created_by_staff_id' => $staffPos->id,
            'advanced_by_staff_id' => $staffCocina->id,
            'finished_by_staff_id' => $staffCocina->id,
            'status' => Orden::STATUS_ENTREGADA,
        ]);

        $this->assertDatabaseHas('orden_detalles', [
            'id' => $detalleId,
            'advanced_by_staff_id' => $staffCocina->id,
            'finished_by_staff_id' => $staffCocina->id,
            'status' => OrdenDetalle::STATUS_LISTO,
        ]);

        $this->assertNotNull(
            \App\Models\Orden::query()->whereKey($ordenId)->value('seconds_total_listo')
        );
    }

    public function test_maestro_can_load_kitchen_board_by_selected_sucursal(): void
    {
        [$user, $negocio, $sucursal, $esquite] = $this->seedPosCatalog();

        $otra = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Norte',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);
        $this->abrirCaja($sucursal->id, 50);
        $this->abrirCaja($otra->id, 50);

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Mesa A',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [
                ['producto_id' => $esquite->id, 'cantidad' => 1],
            ],
        ])->assertCreated();

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Mesa B',
            'sucursal_id' => $otra->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [
                ['producto_id' => $esquite->id, 'cantidad' => 1],
            ],
        ])->assertCreated();

        $this->getJson('/api/ordenes/cocina')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $response = $this->getJson('/api/ordenes/cocina?sucursal_id='.$sucursal->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sucursal.id', $sucursal->id)
            ->assertJsonPath('data.nuevo.0.nombre_cliente', 'Mesa A');

        $this->assertCount(1, $response->json('data.nuevo'));
        $this->assertCount(0, $response->json('data.en_preparacion'));
        $this->assertSame($response->json('data.nuevo'), $response->json('data.activos'));
    }

    public function test_order_number_restarts_per_sucursal(): void
    {
        [$user, $negocio, $sucursal, $esquite] = $this->seedPosCatalog();

        $otra = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Norte',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);
        $this->abrirCaja($sucursal->id, 50);
        $this->abrirCaja($otra->id, 50);

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Centro 1',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $esquite->id, 'cantidad' => 1]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.orden.numero_orden', '000001');

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Centro 2',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $esquite->id, 'cantidad' => 1]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.orden.numero_orden', '000002');

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Norte 1',
            'sucursal_id' => $otra->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [['producto_id' => $esquite->id, 'cantidad' => 1]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.orden.numero_orden', '000001')
            ->assertJsonPath('data.orden.sucursal_id', $otra->id);
    }

    public function test_maestro_can_list_ordenes_filtered_by_selected_sucursal(): void
    {
        [$user, $negocio, $sucursal, $esquite] = $this->seedPosCatalog();

        Sanctum::actingAs($user);
        $this->abrirCaja($sucursal->id, 50);

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Solo centro',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [
                ['producto_id' => $esquite->id, 'cantidad' => 1],
            ],
        ])->assertCreated();

        $this->getJson('/api/ordenes?sucursal_id='.$sucursal->id)
            ->assertOk()
            ->assertJsonPath('data.ordenes.0.sucursal_id', $sucursal->id)
            ->assertJsonPath('data.meta.total', 1);
    }

    private function abrirCaja(?int $sucursalId = null, float $fondo = 0): void
    {
        $payload = ['fondo_inicial' => $fondo];
        if ($sucursalId !== null) {
            $payload['sucursal_id'] = $sucursalId;
        }

        $this->postJson('/api/turnos-caja/abrir', $payload)->assertCreated();
    }

    /**
     * @return array{0: User, 1: \App\Models\Negocio, 2: Sucursal, 3: \App\Models\Producto, 4: \App\Models\Producto}
     */
    private function seedPosCatalog(): array
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Taquería La Isla',
            'phone' => '5512345678',
            'needs_invoice' => false,
        ]);

        $sucursal = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Centro',
            'is_active' => true,
        ]);

        $categoria = $negocio->categoriaProductos()->create([
            'name' => 'Esquites',
        ]);

        $esquite = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Esquite chico',
            'price' => 50,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $ramen = $negocio->productos()->create([
            'categoria_producto_id' => $categoria->id,
            'name' => 'Ramen rosa',
            'price' => 70,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return [$user, $negocio, $sucursal, $esquite, $ramen];
    }
}
