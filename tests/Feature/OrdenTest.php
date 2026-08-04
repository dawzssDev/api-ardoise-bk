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
