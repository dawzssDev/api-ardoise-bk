<?php

namespace Tests\Feature;

use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\Role;
use App\Models\Sucursal;
use App\Models\TipoVenta;
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
            'seconds_in_caja' => 45,
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
            ->assertJsonPath('data.orden.seconds_in_caja', 45)
            ->assertJsonPath('data.orden.tiempo_en_caja', 45)
            ->assertJsonPath('data.orden.staff_creo', null)
            ->assertJsonPath('data.orden.detalles.0.nombre_pedido', 'Esquite chico')
            ->assertJsonPath('data.orden.detalles.1.observaciones', 'Sin cebolla');

        $this->assertDatabaseHas('ordenes', [
            'negocio_id' => $negocio->id,
            'order_number' => 1,
            'customer_name' => 'Luis',
            'total' => 120.00,
            'seconds_in_caja' => 45,
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

    public function test_orden_detalle_applies_tipo_venta_discount_from_backend(): void
    {
        [$user, $negocio, $sucursal, $esquite, $ramen] = $this->seedPosCatalog();

        $policia = $negocio->tiposVenta()->create([
            'name' => 'Policía',
            'tipo_descuento' => TipoVenta::TIPO_PORCENTAJE,
            'valor_descuento' => 20,
            'status' => true,
        ]);

        $cortesia = $negocio->tiposVenta()->create([
            'name' => 'Cortesía',
            'tipo_descuento' => TipoVenta::TIPO_GRATIS,
            'valor_descuento' => null,
            'status' => true,
        ]);

        Sanctum::actingAs($user);
        $this->abrirCaja($sucursal->id, 100);

        $response = $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Cliente descuento',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [
                [
                    'producto_id' => $ramen->id,
                    'cantidad' => 1,
                    // Front manda precio "mentiroso"; el backend debe ignorarlo.
                    'precio' => 1,
                    'tipo_venta_id' => $policia->id,
                ],
                [
                    'producto_id' => $esquite->id,
                    'cantidad' => 1,
                    'tipo_venta_id' => $cortesia->id,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.orden.total', '56.00')
            ->assertJsonPath('data.orden.detalles.0.precio_lista', '70.00')
            ->assertJsonPath('data.orden.detalles.0.price', '56.00')
            ->assertJsonPath('data.orden.detalles.0.tipo_venta.name', 'Policía')
            ->assertJsonPath('data.orden.detalles.1.precio_lista', '50.00')
            ->assertJsonPath('data.orden.detalles.1.price', '0.00')
            ->assertJsonPath('data.orden.detalles.1.tipo_venta.name', 'Cortesía');

        $this->assertDatabaseHas('orden_detalles', [
            'producto_id' => $ramen->id,
            'tipo_venta_id' => $policia->id,
            'precio_lista' => 70.00,
            'price' => 56.00,
        ]);
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

    public function test_cannot_order_product_inactive_in_sucursal(): void
    {
        [$user, $negocio, $sucursal, $esquite] = $this->seedPosCatalog();

        Sanctum::actingAs($user);

        $this->putJson('/api/stock-productos', [
            'sucursal_id' => $sucursal->id,
            'producto_id' => $esquite->id,
            'stock_fisico' => 8,
            'stock_minimo' => 1,
            'activo' => false,
        ])->assertOk();

        $this->abrirCaja($sucursal->id, 50);

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Mesa 3',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [
                ['producto_id' => $esquite->id, 'cantidad' => 1],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'El producto Esquite chico no está disponible en esta sucursal.');
    }

    public function test_hoy_returns_todays_ordenes_grouped(): void
    {
        [$user, $negocio, $sucursal, $esquite] = $this->seedPosCatalog();

        Sanctum::actingAs($user);
        $this->abrirCaja($sucursal->id, 50);

        $ordenId = $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Hoy',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [
                ['producto_id' => $esquite->id, 'cantidad' => 1, 'precio' => 50],
            ],
        ])->assertCreated()->json('data.orden.id');

        $this->getJson('/api/ordenes/hoy?sucursal_id='.$sucursal->id)
            ->assertOk()
            ->assertJsonPath('data.fecha', now()->toDateString())
            ->assertJsonPath('data.sucursal.id', $sucursal->id)
            ->assertJsonPath('data.ordenes.0.id', $ordenId)
            ->assertJsonPath('data.nuevo.0.id', $ordenId);
    }

    public function test_por_fecha_defaults_to_today_and_filters_by_chosen_date(): void
    {
        [$user, $negocio, $sucursal, $esquite] = $this->seedPosCatalog();

        Sanctum::actingAs($user);
        $this->abrirCaja($sucursal->id, 50);

        $hoyId = $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Hoy',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [
                ['producto_id' => $esquite->id, 'cantidad' => 1, 'precio' => 50],
            ],
        ])->assertCreated()->json('data.orden.id');

        $ayer = now()->subDay()->toDateString();
        $ayerId = \App\Models\Orden::query()->findOrFail($hoyId)->replicate();
        $ayerId->customer_name = 'Ayer';
        $ayerId->order_number = 999001;
        $ayerId->created_at = now()->subDay()->setTime(12, 0);
        $ayerId->updated_at = $ayerId->created_at;
        $ayerId->save();

        $hoyResp = $this->getJson('/api/ordenes/por-fecha?sucursal_id='.$sucursal->id)
            ->assertOk()
            ->assertJsonPath('data.fecha', now()->toDateString());

        $hoyIds = collect($hoyResp->json('data.ordenes'))->pluck('id');
        $this->assertTrue($hoyIds->contains($hoyId));
        $this->assertFalse($hoyIds->contains($ayerId->id));

        $this->getJson('/api/ordenes/por-fecha?sucursal_id='.$sucursal->id.'&fecha='.$ayer)
            ->assertOk()
            ->assertJsonPath('data.fecha', $ayer)
            ->assertJsonPath('data.ordenes.0.id', $ayerId->id)
            ->assertJsonPath('data.sucursal.id', $sucursal->id);

        $this->getJson('/api/ordenes/por-fecha?sucursal_id='.$sucursal->id.'&fecha=29-08-2026')
            ->assertStatus(422);
    }

    public function test_can_cancel_detalle_negates_price_and_recalculates_orden_total(): void
    {
        [$user, $negocio, $sucursal, $esquite, $ramen] = $this->seedPosCatalog();

        Sanctum::actingAs($user);
        $this->abrirCaja($sucursal->id, 100);

        $orden = $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Cancelar producto',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [
                ['producto_id' => $esquite->id, 'cantidad' => 1, 'precio' => 100],
                ['producto_id' => $ramen->id, 'cantidad' => 1, 'precio' => 100],
                ['producto_id' => $esquite->id, 'cantidad' => 1, 'precio' => 100],
            ],
        ])->assertCreated();

        $ordenId = $orden->json('data.orden.id');
        $this->assertSame('300.00', $orden->json('data.orden.total'));

        $detalleCancelar = collect($orden->json('data.orden.detalles'))
            ->firstWhere('producto_id', $ramen->id)['id'];

        $this->postJson("/api/ordenes/{$ordenId}/detalles/cancelar", [
            'detalle_ids' => [$detalleCancelar],
        ])
            ->assertOk()
            ->assertJsonPath('data.orden.total', '200.00');

        $this->assertDatabaseHas('orden_detalles', [
            'id' => $detalleCancelar,
            'status' => OrdenDetalle::STATUS_CANCELADO,
            'price' => -100.00,
        ]);

        $this->assertDatabaseHas('ordenes', [
            'id' => $ordenId,
            'total' => 200.00,
        ]);

        $this->assertDatabaseHas('tb_ventas', [
            'orden_id' => $ordenId,
            'total' => 200.00,
        ]);
    }

    public function test_can_create_orden_with_split_payments(): void
    {
        [$user, $negocio, $sucursal, $esquite, $ramen] = $this->seedPosCatalog();

        Sanctum::actingAs($user);
        $open = $this->postJson('/api/turnos-caja/abrir', [
            'sucursal_id' => $sucursal->id,
            'fondo_inicial' => 100,
        ])->assertCreated();
        $turnoId = $open->json('data.turno.id');

        $response = $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Mixto',
            'sucursal_id' => $sucursal->id,
            'pagos' => [
                ['tipo_pago' => 'efectivo', 'monto' => 100],
                ['tipo_pago' => 'tarjeta', 'monto' => 20],
            ],
            'detalles' => [
                ['producto_id' => $esquite->id, 'cantidad' => 1, 'precio' => 50],
                ['producto_id' => $ramen->id, 'cantidad' => 1, 'precio' => 70],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.orden.tipo_pago', Orden::PAYMENT_TYPE_MIXTO)
            ->assertJsonPath('data.orden.total', '120.00')
            ->assertJsonPath('data.orden.pago_mixto.0.tipo_pago', 'efectivo')
            ->assertJsonPath('data.orden.pago_mixto.0.monto', '100.00')
            ->assertJsonPath('data.orden.pago_mixto.1.tipo_pago', 'tarjeta')
            ->assertJsonPath('data.orden.pago_mixto.1.monto', '20.00')
            ->assertJsonPath('data.orden.pagos.0.tipo_pago', 'efectivo');

        $ordenId = $response->json('data.orden.id');

        $this->assertDatabaseCount('orden_pagos', 2);
        $this->assertDatabaseHas('orden_pagos', [
            'orden_id' => $ordenId,
            'payment_type' => 'efectivo',
            'amount' => 100.00,
        ]);
        $this->assertDatabaseHas('tb_ventas', [
            'orden_id' => $ordenId,
            'payment_type' => 'efectivo',
            'total' => 100.00,
        ]);
        $this->assertDatabaseHas('tb_ventas', [
            'orden_id' => $ordenId,
            'payment_type' => 'tarjeta',
            'total' => 20.00,
        ]);
        $this->assertDatabaseCount('tb_ventas', 2);

        $this->getJson('/api/ordenes?per_page=15')
            ->assertOk()
            ->assertJsonPath('data.ordenes.0.id', $ordenId)
            ->assertJsonPath('data.ordenes.0.pago_mixto.0.tipo_pago', 'efectivo')
            ->assertJsonPath('data.ordenes.0.pago_mixto.1.tipo_pago', 'tarjeta');

        $this->getJson("/api/turnos-caja/{$turnoId}/preview")
            ->assertOk()
            ->assertJsonPath('data.preview.total_ventas_efectivo', 100)
            ->assertJsonPath('data.preview.total_ventas_tarjeta', 20)
            ->assertJsonPath('data.preview.total_ventas', 120)
            ->assertJsonPath('data.preview.efectivo_esperado', 100);
    }

    public function test_split_payment_sum_must_match_orden_total(): void
    {
        [$user, , $sucursal, $esquite] = $this->seedPosCatalog();

        Sanctum::actingAs($user);
        $this->abrirCaja($sucursal->id, 100);

        $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Suma incorrecta',
            'sucursal_id' => $sucursal->id,
            'pagos' => [
                ['tipo_pago' => 'efectivo', 'monto' => 30],
                ['tipo_pago' => 'tarjeta', 'monto' => 10],
            ],
            'detalles' => [
                ['producto_id' => $esquite->id, 'cantidad' => 1, 'precio' => 50],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_cancel_all_detalles_removes_venta_from_turno_corte(): void
    {
        [$user, $negocio, $sucursal, $esquite] = $this->seedPosCatalog();

        Sanctum::actingAs($user);
        $open = $this->postJson('/api/turnos-caja/abrir', [
            'sucursal_id' => $sucursal->id,
            'fondo_inicial' => 100,
        ])->assertCreated();
        $turnoId = $open->json('data.turno.id');

        $orden = $this->postJson('/api/ordenes', [
            'nombre_cliente' => 'Todo cancelado',
            'sucursal_id' => $sucursal->id,
            'tipo_pago' => 'efectivo',
            'detalles' => [
                ['producto_id' => $esquite->id, 'cantidad' => 1, 'precio' => 179],
            ],
        ])->assertCreated();

        $ordenId = $orden->json('data.orden.id');
        $detalleId = $orden->json('data.orden.detalles.0.id');

        $this->assertDatabaseHas('tb_ventas', [
            'orden_id' => $ordenId,
            'total' => 179.00,
        ]);

        $this->getJson("/api/turnos-caja/{$turnoId}/preview")
            ->assertOk()
            ->assertJsonPath('data.preview.total_ventas_efectivo', 179);

        $this->postJson("/api/ordenes/{$ordenId}/detalles/cancelar", [
            'detalle_ids' => [$detalleId],
        ])
            ->assertOk()
            ->assertJsonPath('data.orden.total', '0.00');

        $this->assertDatabaseMissing('tb_ventas', [
            'orden_id' => $ordenId,
        ]);

        $this->getJson("/api/turnos-caja/{$turnoId}/preview")
            ->assertOk()
            ->assertJsonPath('data.preview.total_ventas_efectivo', 0)
            ->assertJsonPath('data.preview.total_ventas', 0);
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
