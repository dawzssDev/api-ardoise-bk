<?php

namespace Tests\Feature;

use App\Models\Negocio;
use App\Models\Role;
use App\Models\Staff;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_staff_user(): void
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

        $role = $negocio->roles()->create([
            'name' => 'Cajero',
            'permissions' => Role::defaultPermissions(),
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $empleado = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'first_name' => 'Ana',
            'paternal_surname' => 'Ruiz',
            'employee_number' => 'EMP-001',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/staff', [
            'usuario' => 'ana.ruiz',
            'contrasena' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'empleadoResponsable' => $empleado->id,
            'status' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.staff.username', 'ana.ruiz')
            ->assertJsonPath('data.staff.empleado_id', $empleado->id)
            ->assertJsonPath('data.staff.role.name', 'Cajero')
            ->assertJsonPath('data.staff.has_password_authorization', false)
            ->assertJsonMissingPath('data.staff.password')
            ->assertJsonMissingPath('data.staff.password_authorization');

        $this->assertDatabaseHas('staff', [
            'negocio_id' => $negocio->id,
            'username' => 'ana.ruiz',
            'empleado_id' => $empleado->id,
            'created_by' => $user->id,
        ]);
    }

    public function test_user_can_save_and_update_password_authorization(): void
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

        $role = $negocio->roles()->create([
            'name' => 'Cajero',
            'permissions' => Role::defaultPermissions(),
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $empleado = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'first_name' => 'Ana',
            'paternal_surname' => 'Ruiz',
            'employee_number' => 'EMP-PIN',
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        Sanctum::actingAs($user);

        $staffId = $this->postJson('/api/staff', [
            'usuario' => 'ana.pin',
            'contrasena' => 'secreto123',
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'empleado_id' => $empleado->id,
            'password_authorization' => '123456',
        ])
            ->assertCreated()
            ->assertJsonPath('data.staff.has_password_authorization', true)
            ->assertJsonMissingPath('data.staff.password_authorization')
            ->json('data.staff.id');

        $staff = Staff::query()->findOrFail($staffId);
        $this->assertTrue(Hash::check('123456', $staff->password_authorization));
        $this->assertNotSame('123456', $staff->password_authorization);

        $this->getJson("/api/staff/{$staffId}")
            ->assertOk()
            ->assertJsonPath('data.staff.has_password_authorization', true)
            ->assertJsonMissingPath('data.staff.password_authorization');

        $this->putJson("/api/staff/{$staffId}", [
            'passwordAuthorization' => '654321',
        ])
            ->assertOk()
            ->assertJsonPath('data.staff.has_password_authorization', true);

        $staff->refresh();
        $this->assertTrue(Hash::check('654321', $staff->password_authorization));
        $this->assertFalse(Hash::check('123456', $staff->password_authorization));

        $this->putJson("/api/staff/{$staffId}", [
            'password_authorization' => '12345',
        ])->assertStatus(422);

        $this->putJson("/api/staff/{$staffId}", [
            'password_authorization' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.staff.has_password_authorization', false);

        $this->assertNull($staff->refresh()->password_authorization);
    }

    public function test_staff_login_sucursal_without_password_authorization_returns_zero(): void
    {
        [$staffSucursal1] = $this->seedStaffAuthorizationScenario(pinInSucursal1: false);

        Sanctum::actingAs($staffSucursal1);

        $this->getJson('/api/staff/password-authorization')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_password_authorization', 0)
            ->assertJsonPath('data.staff_ids', []);
    }

    public function test_staff_login_sucursal_with_password_authorization_returns_one_and_ids(): void
    {
        [$staffSucursal1, $staffConPin, $otroStaffConPin] = $this->seedStaffAuthorizationScenario(pinInSucursal1: true);

        Sanctum::actingAs($staffSucursal1);

        $this->getJson('/api/staff/password-authorization')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_password_authorization', 1)
            ->assertJsonPath('data.staff_ids', [$staffConPin->id, $otroStaffConPin->id]);
    }

    public function test_password_authorization_is_scoped_to_logged_in_sucursal(): void
    {
        [$staffSucursal1] = $this->seedStaffAuthorizationScenario(
            pinInSucursal1: false,
            pinInSucursal2: true,
        );

        Sanctum::actingAs($staffSucursal1);

        $this->getJson('/api/staff/password-authorization')
            ->assertOk()
            ->assertJsonPath('data.has_password_authorization', 0)
            ->assertJsonPath('data.staff_ids', []);
    }

    public function test_master_user_can_query_password_authorization_by_sucursal(): void
    {
        [, $staffConPin, $otroStaffConPin, $user, $sucursal1] = $this->seedStaffAuthorizationScenario(pinInSucursal1: true);

        Sanctum::actingAs($user);

        $this->getJson('/api/staff/password-authorization')
            ->assertStatus(422);

        $this->getJson('/api/staff/password-authorization?sucursal_id='.$sucursal1->id)
            ->assertOk()
            ->assertJsonPath('data.has_password_authorization', 1)
            ->assertJsonPath('data.staff_ids', [$staffConPin->id, $otroStaffConPin->id]);
    }

    public function test_staff_can_verify_password_authorization_against_staff_ids(): void
    {
        [$staffSucursal1, $staffConPin, $otroStaffConPin] = $this->seedStaffAuthorizationScenario(pinInSucursal1: true);

        Sanctum::actingAs($staffSucursal1);

        $this->postJson('/api/staff/password-authorization', [
            'password_authorization' => '123456',
            'staff_ids' => [$staffConPin->id, $otroStaffConPin->id],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.staff_id', $staffConPin->id);
    }

    public function test_staff_can_verify_pin_alias_from_frontend_payload(): void
    {
        [$staffSucursal1, $staffConPin] = $this->seedStaffAuthorizationScenario(pinInSucursal1: true);

        Sanctum::actingAs($staffSucursal1);

        $this->postJson('/api/staff/password-authorization', [
            'pin' => 123456,
            'staff_ids' => [$staffConPin->id],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.staff_id', $staffConPin->id);
    }

    public function test_staff_can_verify_second_staff_password_authorization(): void
    {
        [$staffSucursal1, $staffConPin, $otroStaffConPin] = $this->seedStaffAuthorizationScenario(pinInSucursal1: true);

        Sanctum::actingAs($staffSucursal1);

        $this->postJson('/api/staff/password-authorization', [
            'password_authorization' => '654321',
            'staff_ids' => [$staffConPin->id, $otroStaffConPin->id],
        ])
            ->assertOk()
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.staff_id', $otroStaffConPin->id);
    }

    public function test_wrong_password_authorization_is_rejected(): void
    {
        [$staffSucursal1, $staffConPin, $otroStaffConPin] = $this->seedStaffAuthorizationScenario(pinInSucursal1: true);

        Sanctum::actingAs($staffSucursal1);

        $this->postJson('/api/staff/password-authorization', [
            'password_authorization' => '999999',
            'staff_ids' => [$staffConPin->id, $otroStaffConPin->id],
        ])
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('data', null);
    }

    public function test_password_authorization_from_another_sucursal_is_rejected(): void
    {
        [$staffSucursal1] = $this->seedStaffAuthorizationScenario(
            pinInSucursal1: false,
            pinInSucursal2: true,
        );

        $staffSucursal2 = Staff::query()->where('username', 'carlos.s2')->firstOrFail();

        Sanctum::actingAs($staffSucursal1);

        $this->postJson('/api/staff/password-authorization', [
            'password_authorization' => '111111',
            'staff_ids' => [$staffSucursal2->id],
        ])->assertUnauthorized();
    }

    public function test_verify_password_authorization_requires_valid_payload(): void
    {
        [$staffSucursal1] = $this->seedStaffAuthorizationScenario(pinInSucursal1: true);

        Sanctum::actingAs($staffSucursal1);

        $this->postJson('/api/staff/password-authorization', [])
            ->assertStatus(422);

        $this->postJson('/api/staff/password-authorization', [
            'password_authorization' => '12345',
            'staff_ids' => [1],
        ])->assertStatus(422);
    }

    /**
     * @return array{0: Staff, 1: Staff, 2: Staff, 3: User, 4: Sucursal}
     */
    private function seedStaffAuthorizationScenario(
        bool $pinInSucursal1 = false,
        bool $pinInSucursal2 = false,
    ): array {
        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio A',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        $sucursal1 = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Sucursal 1',
            'is_active' => true,
        ]);
        $sucursal2 = $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Sucursal 2',
            'is_active' => true,
        ]);
        $negocio->sucursales()->create([
            'type' => Sucursal::TYPE_SUCURSAL,
            'name' => 'Sucursal 3',
            'is_active' => true,
        ]);

        $role = $negocio->roles()->create([
            'name' => 'Cajero',
            'permissions' => Role::defaultPermissions(),
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $staffSucursal1 = $this->createStaffForSucursal($negocio, $user, $role, $sucursal1, 'ana.s1', 'EMP-S1');
        $staffConPin = $this->createStaffForSucursal(
            $negocio,
            $user,
            $role,
            $sucursal1,
            'luis.s1',
            'EMP-S1B',
            $pinInSucursal1 ? '123456' : null,
        );
        $otroStaffConPin = $this->createStaffForSucursal(
            $negocio,
            $user,
            $role,
            $sucursal1,
            'bety.s1',
            'EMP-S1C',
            $pinInSucursal1 ? '654321' : null,
        );
        $this->createStaffForSucursal(
            $negocio,
            $user,
            $role,
            $sucursal2,
            'carlos.s2',
            'EMP-S2',
            $pinInSucursal2 ? '111111' : null,
        );

        return [$staffSucursal1, $staffConPin, $otroStaffConPin, $user, $sucursal1];
    }

    private function createStaffForSucursal(
        Negocio $negocio,
        User $user,
        Role $role,
        Sucursal $sucursal,
        string $username,
        string $employeeNumber,
        ?string $passwordAuthorization = null,
    ): Staff {
        $empleado = $negocio->empleados()->create([
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'first_name' => $username,
            'paternal_surname' => 'Test',
            'employee_number' => $employeeNumber,
            'status' => 'activo',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $negocio->staff()->create([
            'username' => $username,
            'password' => 'secreto123',
            'password_authorization' => $passwordAuthorization,
            'sucursal_id' => $sucursal->id,
            'role_id' => $role->id,
            'empleado_id' => $empleado->id,
            'status' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
    }
}
