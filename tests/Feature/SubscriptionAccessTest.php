<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Staff;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\StripeService;
use App\Services\SubscriptionAccessService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Stripe\Subscription as StripeSubscription;
use Tests\TestCase;

class SubscriptionAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_login_includes_block_pos_and_subscription_access(): void
    {
        $user = User::factory()->create([
            'email' => 'dueño@example.com',
            'password' => 'password123',
            'user_ardo_vip' => 0,
            'block_POS' => 0,
        ]);
        $this->createSubscription($user, [
            'status' => 'active',
            'access_until' => now()->addDays(10),
            'current_period_end' => now()->addDays(10),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'dueño@example.com',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.block_POS', 0)
            ->assertJsonPath('data.subscription_access.access_state', 'active')
            ->assertJsonPath('data.subscription_access.pos_blocked', false);
    }

    public function test_cancel_schedules_period_end_without_refund(): void
    {
        $user = User::factory()->create(['user_ardo_vip' => 0]);
        $this->createSubscription($user, [
            'stripe_subscription_id' => 'sub_cancel_1',
            'status' => 'active',
            'access_until' => now()->addDays(12),
            'current_period_end' => now()->addDays(12),
        ]);
        $token = $user->createToken('api')->plainTextToken;

        $periodEnd = now()->addDays(12)->timestamp;
        $stripeSub = StripeSubscription::constructFrom([
            'id' => 'sub_cancel_1',
            'status' => 'active',
            'cancel_at_period_end' => true,
            'current_period_end' => $periodEnd,
        ]);

        $this->mock(StripeService::class, function (MockInterface $mock) use ($user, $stripeSub) {
            $mock->shouldReceive('cancelSubscription')
                ->once()
                ->andReturnUsing(function () use ($user, $stripeSub) {
                    $user->subscriptions()
                        ->where('stripe_subscription_id', 'sub_cancel_1')
                        ->update([
                            'cancel_at_period_end' => true,
                            'status' => 'active',
                        ]);

                    return $stripeSub;
                });
        });

        $this->withToken($token)
            ->deleteJson('/api/subscriptions/sub_cancel_1')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.cancel_at_period_end', true)
            ->assertJsonPath('data.refund', false)
            ->assertJsonPath('data.subscription_access.access_state', 'canceled_pending');
    }

    public function test_canceled_subscription_stays_open_until_day_after_cutoff(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));

        $user = User::factory()->create(['user_ardo_vip' => 0, 'block_POS' => 0]);
        $negocio = $this->createNegocio($user);
        $this->createSubscription($user, [
            'status' => 'active',
            'cancel_at_period_end' => true,
            'access_until' => Carbon::parse('2026-09-10 23:59:00'),
            'current_period_end' => Carbon::parse('2026-09-10 23:59:00'),
        ]);

        $access = app(SubscriptionAccessService::class);
        $snapshot = $access->snapshot($user->fresh());
        $this->assertSame('canceled_pending', $snapshot['access_state']);
        $this->assertFalse($snapshot['pos_blocked']);
        $this->assertSame(0, (int) $user->fresh()->block_POS);

        Sanctum::actingAs($user);
        $this->getJson('/api/sucursales')->assertOk();
        $this->getJson('/api/negocio')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-11 00:10:00'));
        $access->applyForUser($user->fresh());
        $this->assertSame(1, (int) $user->fresh()->block_POS);

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/sucursales')
            ->assertForbidden()
            ->assertJsonPath('data.block_POS', 1);
        $this->getJson('/api/negocio')->assertOk();
        $this->getJson('/api/user')->assertOk();
        $this->getJson('/api/subscriptions')->assertOk();
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.subscription_access.access_state', 'blocked')
            ->assertJsonPath('data.user.block_POS', 1);

        $this->assertTrue($negocio->exists);
    }

    public function test_lapse_warns_on_cutoff_and_blocks_on_day_two(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));

        $user = User::factory()->create(['user_ardo_vip' => 0, 'block_POS' => 0]);
        $this->createNegocio($user);
        $this->createSubscription($user, [
            'status' => 'past_due',
            'cancel_at_period_end' => false,
            'access_until' => Carbon::parse('2026-09-10 08:00:00'),
            'current_period_end' => Carbon::parse('2026-09-10 08:00:00'),
        ]);

        $access = app(SubscriptionAccessService::class);

        $day0 = $access->snapshot($user->fresh());
        $this->assertSame('expired', $day0['access_state']);
        $this->assertFalse($day0['pos_blocked']);
        $this->assertStringContainsString('ha vencido', (string) $day0['message']);

        Carbon::setTestNow(Carbon::parse('2026-09-11 09:00:00'));
        $day1 = $access->snapshot($user->fresh());
        $this->assertSame('warning', $day1['access_state']);
        $this->assertFalse($day1['pos_blocked']);
        $this->assertStringContainsString('mañana', (string) $day1['message']);

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/sucursales')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-09-12 09:00:00'));
        $day2 = $access->snapshot($user->fresh());
        $this->assertSame('blocked', $day2['access_state']);
        $this->assertTrue($day2['pos_blocked']);
        $this->assertSame(1, (int) $user->fresh()->block_POS);

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/sucursales')->assertForbidden();
        $this->putJson('/api/negocio', ['name' => 'Reactivar'])->assertOk();
    }

    public function test_staff_cannot_login_and_tokens_are_revoked_when_owner_is_blocked(): void
    {
        $user = User::factory()->create(['user_ardo_vip' => 0, 'block_POS' => 0]);
        $negocio = $this->createNegocio($user);
        $staff = $this->createStaff($user, $negocio, 'cajero.uno');

        $staffToken = $staff->createToken('api')->plainTextToken;
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->createSubscription($user, [
            'status' => 'canceled',
            'cancel_at_period_end' => true,
            'access_until' => now()->subDay(),
            'current_period_end' => now()->subDay(),
            'canceled_at' => now()->subDay(),
        ]);

        app(SubscriptionAccessService::class)->applyForUser($user->fresh());

        $this->assertSame(1, (int) $user->fresh()->block_POS);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->postJson('/api/auth/login', [
            'usuario' => 'cajero.uno',
            'password' => 'secreto123',
        ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->withToken($staffToken)
            ->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_renewal_unblocks_owner_and_allows_staff_login_again(): void
    {
        $user = User::factory()->create(['user_ardo_vip' => 0, 'block_POS' => 1]);
        $negocio = $this->createNegocio($user);
        $this->createStaff($user, $negocio, 'cajero.dos');
        $this->createSubscription($user, [
            'status' => 'canceled',
            'cancel_at_period_end' => true,
            'access_until' => now()->subDays(3),
            'current_period_end' => now()->subDays(3),
        ]);

        $this->createSubscription($user, [
            'stripe_subscription_id' => 'sub_renewed',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'access_until' => now()->addMonth(),
            'current_period_end' => now()->addMonth(),
        ]);

        app(SubscriptionAccessService::class)->applyForUser($user->fresh());

        $this->assertSame(0, (int) $user->fresh()->block_POS);

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/sucursales')->assertOk();

        $this->postJson('/api/auth/login', [
            'usuario' => 'cajero.dos',
            'password' => 'secreto123',
        ])
            ->assertOk()
            ->assertJsonPath('data.type', 'staff');
    }

    public function test_sync_access_command_blocks_expired_cancel(): void
    {
        $user = User::factory()->create(['user_ardo_vip' => 0, 'block_POS' => 0]);
        $this->createNegocio($user);
        $this->createSubscription($user, [
            'status' => 'canceled',
            'cancel_at_period_end' => true,
            'access_until' => now()->subDays(2),
            'current_period_end' => now()->subDays(2),
        ]);

        $this->artisan('subscriptions:sync-access')
            ->assertSuccessful();

        $this->assertSame(1, (int) $user->fresh()->block_POS);
    }

    public function test_incomplete_renewal_does_not_unblock_while_blocked(): void
    {
        $user = User::factory()->create(['user_ardo_vip' => 0, 'block_POS' => 1]);
        $this->createNegocio($user);
        $this->createSubscription($user, [
            'stripe_subscription_id' => 'sub_old',
            'status' => 'canceled',
            'cancel_at_period_end' => true,
            'access_until' => now()->subDays(5),
        ]);
        $this->createSubscription($user, [
            'stripe_subscription_id' => 'sub_incomplete',
            'status' => 'incomplete',
            'cancel_at_period_end' => false,
            'access_until' => now()->addMonth(),
            'current_period_end' => now()->addMonth(),
        ]);

        app(SubscriptionAccessService::class)->applyForUser($user->fresh());

        $this->assertSame(1, (int) $user->fresh()->block_POS);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSubscription(User $user, array $overrides = []): void
    {
        $user->subscriptions()->create(array_merge([
            'stripe_subscription_id' => 'sub_'.uniqid(),
            'stripe_price_id' => 'price_test',
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
            'access_until' => now()->addMonth(),
            'cancel_at_period_end' => false,
        ], $overrides));
    }

    private function createNegocio(User $user)
    {
        return $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);
    }

    private function createStaff(User $user, $negocio, string $username): Staff
    {
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
            'employee_number' => 'EMP-'.$username,
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
