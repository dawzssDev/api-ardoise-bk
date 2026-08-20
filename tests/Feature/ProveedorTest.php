<?php

namespace Tests\Feature;

use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProveedorTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_proveedor_for_negocio(): void
    {
        [$user] = $this->actingWithNegocio();

        $this->postJson('/api/proveedores', [
            'nombre' => 'Distribuidora Norte',
            'razon_social' => 'Distribuidora Norte SA de CV',
            'rfc' => 'DNOR860101AAA',
            'telefono' => '6671112233',
            'correo' => 'compras@norte.test',
            'contacto' => 'Ana Ruiz',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.proveedor.name', 'Distribuidora Norte')
            ->assertJsonPath('data.proveedor.legal_name', 'Distribuidora Norte SA de CV')
            ->assertJsonPath('data.proveedor.status', 1)
            ->assertJsonPath('data.proveedor.status_label', 'activo')
            ->assertJsonMissingPath('data.proveedor.sucursal_id');

        $this->assertDatabaseHas('proveedores', [
            'negocio_id' => $user->negocio->id,
            'name' => 'Distribuidora Norte',
            'status' => Proveedor::STATUS_ACTIVO,
        ]);
    }

    public function test_user_can_list_proveedores_of_own_negocio(): void
    {
        [$user, $negocio] = $this->actingWithNegocio();

        $negocio->proveedores()->create([
            'name' => 'Proveedor A',
            'status' => Proveedor::STATUS_ACTIVO,
            'created_by' => $user->id,
        ]);
        $negocio->proveedores()->create([
            'name' => 'Proveedor B',
            'status' => Proveedor::STATUS_BAJA,
            'created_by' => $user->id,
        ]);

        $this->getJson('/api/proveedores')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.meta.total', 2);

        $this->getJson('/api/proveedores?status=1')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1)
            ->assertJsonPath('data.proveedores.0.name', 'Proveedor A');
    }

    public function test_user_can_get_proveedor_by_id(): void
    {
        [$user, $negocio] = $this->actingWithNegocio();

        $proveedor = $negocio->proveedores()->create([
            'name' => 'Carnes del Pacífico',
            'phone' => '6670001111',
            'status' => Proveedor::STATUS_ACTIVO,
            'created_by' => $user->id,
        ]);

        $this->getJson('/api/proveedores/'.$proveedor->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.proveedor.id', $proveedor->id)
            ->assertJsonPath('data.proveedor.name', 'Carnes del Pacífico');
    }

    public function test_user_can_update_proveedor(): void
    {
        [$user, $negocio] = $this->actingWithNegocio();

        $proveedor = $negocio->proveedores()->create([
            'name' => 'Lácteos Sur',
            'status' => Proveedor::STATUS_ACTIVO,
            'created_by' => $user->id,
        ]);

        $this->putJson('/api/proveedores/'.$proveedor->id, [
            'nombre' => 'Lácteos Sur Premium',
            'telefono' => '6672223344',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.proveedor.name', 'Lácteos Sur Premium')
            ->assertJsonPath('data.proveedor.phone', '6672223344');
    }

    public function test_delete_proveedor_changes_status_to_baja(): void
    {
        [$user, $negocio] = $this->actingWithNegocio();

        $proveedor = $negocio->proveedores()->create([
            'name' => 'Abarrotes Centro',
            'status' => Proveedor::STATUS_ACTIVO,
            'created_by' => $user->id,
        ]);

        $this->deleteJson('/api/proveedores/'.$proveedor->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Proveedor dado de baja correctamente.')
            ->assertJsonPath('data.proveedor.status', 0)
            ->assertJsonPath('data.proveedor.status_label', 'baja');

        $this->assertDatabaseHas('proveedores', [
            'id' => $proveedor->id,
            'status' => Proveedor::STATUS_BAJA,
        ]);
    }

    public function test_cannot_delete_proveedor_already_baja(): void
    {
        [$user, $negocio] = $this->actingWithNegocio();

        $proveedor = $negocio->proveedores()->create([
            'name' => 'Ya dado de baja',
            'status' => Proveedor::STATUS_BAJA,
            'created_by' => $user->id,
        ]);

        $this->deleteJson('/api/proveedores/'.$proveedor->id)
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'El proveedor ya está dado de baja.');
    }

    public function test_cannot_access_proveedor_from_another_negocio(): void
    {
        [, $negocio] = $this->actingWithNegocio();

        $other = User::factory()->create();
        $otherNegocio = $other->negocio()->create([
            'name' => 'Otro Negocio',
            'phone' => '6679990000',
            'needs_invoice' => false,
        ]);

        $ajeno = $otherNegocio->proveedores()->create([
            'name' => 'Proveedor Ajeno',
            'status' => Proveedor::STATUS_ACTIVO,
            'created_by' => $other->id,
        ]);

        $this->getJson('/api/proveedores/'.$ajeno->id)->assertNotFound();
        $this->putJson('/api/proveedores/'.$ajeno->id, ['name' => 'Hack'])->assertNotFound();
        $this->deleteJson('/api/proveedores/'.$ajeno->id)->assertNotFound();

        $this->assertDatabaseHas('proveedores', [
            'id' => $ajeno->id,
            'negocio_id' => $otherNegocio->id,
            'name' => 'Proveedor Ajeno',
            'status' => Proveedor::STATUS_ACTIVO,
        ]);
        $this->assertSame($negocio->id, $negocio->fresh()->id);
    }

    public function test_cannot_create_duplicate_name_in_same_negocio(): void
    {
        [$user, $negocio] = $this->actingWithNegocio();

        $negocio->proveedores()->create([
            'name' => 'Repetido',
            'status' => Proveedor::STATUS_ACTIVO,
            'created_by' => $user->id,
        ]);

        $this->postJson('/api/proveedores', ['name' => 'Repetido'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * @return array{0: User, 1: \App\Models\Negocio}
     */
    private function actingWithNegocio(): array
    {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        return [$user->fresh(), $negocio];
    }
}
