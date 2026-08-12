<?php

namespace Tests\Feature;

use App\Models\CuentaPorCobrar;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\TipoVenta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CuentaPorCobrarTest extends TestCase
{
    use RefreshDatabase;

    public function test_diferir_cobro_creates_cuenta_and_excludes_from_orden_total(): void
    {
        [$user, $negocio, $sucursal, $esquite, $ramen, $empleado] = $this->seedCatalogWithEmpleado();

        $consumo = $negocio->tiposVenta()->create([
            'name' => 'Consumo colaborador',
            'tipo_descuento' => TipoVenta::TIPO_NINGUNO,
            'valor_descuento' => null,
            'diferir_cobro' => true,
            'requiere_empleado' => true,
            'status' => true,
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/turnos-caja/abrir', [
            'sucursal_id' => $sucursal->id,
            'fondo_inicial' => 100,
        ])->assertCreated();

        $response = $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Colaborador',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [
                [
                    'producto_id' => $ramen->id,
                    'cantidad' => 1,
                    'tipo_venta_id' => $consumo->id,
                    'empleado_id' => $empleado->id,
                ],
                [
                    'producto_id' => $esquite->id,
                    'cantidad' => 1,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.orden.total', '50.00')
            ->assertJsonPath('data.orden.detalles.0.diferido', true)
            ->assertJsonPath('data.orden.detalles.0.price', '70.00')
            ->assertJsonPath('data.orden.detalles.1.diferido', false);

        $this->assertDatabaseHas('tb_cuentas_por_cobrar', [
            'negocio_id' => $negocio->id,
            'empleado_id' => $empleado->id,
            'concepto' => 'Ramen rosa',
            'monto' => 70.00,
            'status' => CuentaPorCobrar::STATUS_PENDIENTE,
        ]);

        $this->assertDatabaseMissing('tb_ventas', [
            'orden_id' => $response->json('data.orden.id'),
            'total' => 120.00,
        ]);

        $this->assertDatabaseHas('tb_ventas', [
            'orden_id' => $response->json('data.orden.id'),
            'total' => 50.00,
        ]);
    }

    public function test_owner_can_list_resumen_and_pagar_cuentas(): void
    {
        [$user, $negocio, $sucursal, $esquite, $ramen, $empleado] = $this->seedCatalogWithEmpleado();

        $consumo = $negocio->tiposVenta()->create([
            'name' => 'Consumo colaborador',
            'tipo_descuento' => TipoVenta::TIPO_NINGUNO,
            'diferir_cobro' => true,
            'requiere_empleado' => true,
            'status' => true,
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/turnos-caja/abrir', [
            'sucursal_id' => $sucursal->id,
            'fondo_inicial' => 50,
        ])->assertCreated();

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Solo diferido',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [[
                'producto_id' => $ramen->id,
                'cantidad' => 1,
                'tipo_venta_id' => $consumo->id,
                'empleado_id' => $empleado->id,
            ]],
        ])->assertCreated()->assertJsonPath('data.orden.total', '0.00');

        $this->getJson('/api/cuentas-por-cobrar?status=pendiente')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.cuentas.0.empleado.full_name', 'Ana Pérez');

        $this->getJson('/api/cuentas-por-cobrar/resumen')
            ->assertOk()
            ->assertJsonPath('data.resumen.0.empleado_id', $empleado->id)
            ->assertJsonPath('data.resumen.0.total_pendiente', '70.00');

        $cuentaId = $this->getJson('/api/cuentas-por-cobrar')->json('data.cuentas.0.id');

        $this->postJson('/api/cuentas-por-cobrar/'.$cuentaId.'/pagar', [
            'nota' => 'Descontado quincena',
        ])
            ->assertOk()
            ->assertJsonPath('data.cuenta.status', 'pagado')
            ->assertJsonPath('data.cuenta.pagado_por', $user->id);

        $this->assertDatabaseHas('tb_cuentas_por_cobrar', [
            'id' => $cuentaId,
            'status' => CuentaPorCobrar::STATUS_PAGADO,
            'pagado_por' => $user->id,
            'nota' => 'Descontado quincena',
        ]);
    }

    public function test_empleado_id_is_persisted_even_when_tipo_venta_does_not_require_it(): void
    {
        [$user, $negocio, $sucursal, , $ramen, $empleado] = $this->seedCatalogWithEmpleado();

        $tipo = $negocio->tiposVenta()->create([
            'name' => 'Público general',
            'tipo_descuento' => TipoVenta::TIPO_NINGUNO,
            'diferir_cobro' => false,
            'requiere_empleado' => false,
            'status' => true,
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/turnos-caja/abrir', [
            'sucursal_id' => $sucursal->id,
            'fondo_inicial' => 10,
        ])->assertCreated();

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Ana Laura',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'items' => [[
                'producto_id' => $ramen->id,
                'quantity' => 1,
                'tipo_venta_id' => $tipo->id,
                'empleado_id' => $empleado->id,
            ]],
        ])
            ->assertCreated()
            ->assertJsonPath('data.orden.detalles.0.empleado_id', $empleado->id)
            ->assertJsonPath('data.orden.detalles.0.empleado.full_name', 'Ana Pérez')
            ->assertJsonPath('data.orden.detalles.0.empleado.nombre_completo', 'Ana Pérez');
    }

    public function test_diferir_cobro_requires_empleado(): void
    {
        [$user, $negocio, $sucursal, , $ramen] = $this->seedCatalogWithEmpleado();

        $consumo = $negocio->tiposVenta()->create([
            'name' => 'Consumo colaborador',
            'tipo_descuento' => TipoVenta::TIPO_NINGUNO,
            'diferir_cobro' => true,
            'requiere_empleado' => true,
            'status' => true,
        ]);

        Sanctum::actingAs($user);
        $this->postJson('/api/turnos-caja/abrir', [
            'sucursal_id' => $sucursal->id,
            'fondo_inicial' => 10,
        ])->assertCreated();

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Sin empleado',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [[
                'producto_id' => $ramen->id,
                'cantidad' => 1,
                'tipo_venta_id' => $consumo->id,
            ]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    /**
     * @return array{0: User, 1: \App\Models\Negocio, 2: Sucursal, 3: \App\Models\Producto, 4: \App\Models\Producto, 5: \App\Models\Empleado}
     */
    private function seedCatalogWithEmpleado(): array
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

        $role = $negocio->roles()->create([
            'name' => 'Mesero',
            'permissions' => Role::defaultPermissions(),
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $empleado = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'first_name' => 'Ana',
            'paternal_surname' => 'Pérez',
            'maternal_surname' => null,
            'employee_number' => 'EMP-001',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
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

        return [$user, $negocio, $sucursal, $esquite, $ramen, $empleado];
    }
}
