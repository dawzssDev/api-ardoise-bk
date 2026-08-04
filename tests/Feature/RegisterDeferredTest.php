<?php

namespace Tests\Feature;

use App\Models\PendingRegistration;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Mockery\MockInterface;
use Stripe\Subscription as StripeSubscription;
use Tests\TestCase;

class RegisterDeferredTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_stores_pending_without_creating_user(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ana Ruiz',
            'business_name' => 'Taquería La Isla',
            'email' => 'ana@negocio.mx',
            'phone' => '5512345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email', 'ana@negocio.mx')
            ->assertJsonPath('data.user', null)
            ->assertJsonPath('data.token', null)
            ->assertJsonStructure([
                'data' => ['registration_token', 'expires_at', 'email'],
            ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'ana@negocio.mx',
        ]);

        $this->assertDatabaseHas('pending_registrations', [
            'email' => 'ana@negocio.mx',
            'business_name' => 'Taquería La Isla',
            'status' => PendingRegistration::STATUS_PENDING,
        ]);
    }

    public function test_complete_without_payment_does_not_create_user(): void
    {
        $register = $this->postJson('/api/auth/register', [
            'name' => 'Ana Ruiz',
            'business_name' => 'Taquería La Isla',
            'email' => 'ana@negocio.mx',
            'phone' => '5512345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ])->assertCreated();

        $token = $register->json('data.registration_token');

        $this->postJson('/api/auth/register/complete', [
            'registration_token' => $token,
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseMissing('users', [
            'email' => 'ana@negocio.mx',
        ]);
    }

    public function test_checkout_and_complete_creates_user_after_paid_subscription(): void
    {
        config([
            'services.stripe.price_mensual' => 'price_test_mensual',
            'services.stripe.trial_days' => 14,
        ]);

        $register = $this->postJson('/api/auth/register', [
            'name' => 'Ana Ruiz',
            'business_name' => 'Taquería La Isla',
            'email' => 'ana@negocio.mx',
            'phone' => '5512345678',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'terms_accepted' => true,
        ])->assertCreated();

        $registrationToken = $register->json('data.registration_token');

        $subscription = StripeSubscription::constructFrom([
            'id' => 'sub_pending_1',
            'status' => 'incomplete',
            'customer' => 'cus_pending_1',
            'latest_invoice' => [
                'payment_intent' => [
                    'id' => 'seti_or_pi_1',
                    'client_secret' => 'seti_or_pi_1_secret',
                ],
            ],
            'pending_setup_intent' => null,
            'items' => [
                'data' => [
                    ['price' => ['id' => 'price_test_mensual']],
                ],
            ],
            'metadata' => [],
        ]);

        $paidSubscription = StripeSubscription::constructFrom([
            'id' => 'sub_pending_1',
            'status' => 'trialing',
            'customer' => 'cus_pending_1',
            'current_period_end' => now()->addMonth()->timestamp,
            'trial_end' => now()->addDays(14)->timestamp,
            'items' => [
                'data' => [
                    ['price' => ['id' => 'price_test_mensual']],
                ],
            ],
            'metadata' => [
                'user_id' => '1',
            ],
        ]);

        $this->mock(StripeService::class, function (MockInterface $mock) use ($subscription, $paidSubscription) {
            $mock->shouldReceive('isConfiguredPriceId')
                ->with('price_test_mensual')
                ->andReturn(true);

            $mock->shouldReceive('createSubscriptionForPending')
                ->once()
                ->andReturnUsing(function (PendingRegistration $pending) use ($subscription) {
                    $pending->forceFill([
                        'stripe_customer_id' => 'cus_pending_1',
                        'stripe_subscription_id' => 'sub_pending_1',
                        'stripe_price_id' => 'price_test_mensual',
                        'status' => PendingRegistration::STATUS_CHECKOUT,
                    ])->save();

                    return $subscription;
                });

            $mock->shouldReceive('extractSubscriptionClientSecret')
                ->once()
                ->andReturn([
                    'client_secret' => 'seti_or_pi_1_secret',
                    'payment_intent_id' => 'seti_or_pi_1',
                    'subscription_id' => 'sub_pending_1',
                    'intent_type' => 'setup_intent',
                ]);

            $mock->shouldReceive('detectIntentType')->andReturn('setup_intent');
            $mock->shouldReceive('trialDays')->andReturn(14);

            $mock->shouldReceive('isSubscriptionReadyForRegistration')
                ->with('sub_pending_1')
                ->andReturn(true);

            $mock->shouldReceive('attachUserToCustomer')->once();
            $mock->shouldReceive('attachUserToSubscription')
                ->once()
                ->andReturnUsing(function (string $subId, User $user) use ($paidSubscription) {
                    // espejo local mínimo
                    $user->subscriptions()->create([
                        'stripe_subscription_id' => $subId,
                        'stripe_price_id' => 'price_test_mensual',
                        'status' => $paidSubscription->status,
                    ]);
                });
        });

        $this->postJson('/api/auth/register/checkout', [
            'registration_token' => $registrationToken,
            'plan_id' => 'price_test_mensual',
        ])
            ->assertCreated()
            ->assertJsonPath('data.client_secret', 'seti_or_pi_1_secret')
            ->assertJsonPath('data.subscription_id', 'sub_pending_1');

        $this->assertDatabaseMissing('users', [
            'email' => 'ana@negocio.mx',
        ]);

        $complete = $this->postJson('/api/auth/register/complete', [
            'registration_token' => $registrationToken,
        ]);

        $complete->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'user')
            ->assertJsonPath('data.user.email', 'ana@negocio.mx')
            ->assertJsonPath('data.negocio.name', 'Taquería La Isla')
            ->assertJsonStructure([
                'data' => ['token', 'token_type', 'user', 'negocio'],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'ana@negocio.mx',
            'stripe_customer_id' => 'cus_pending_1',
        ]);

        $user = User::query()->where('email', 'ana@negocio.mx')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('password123', $user->password));

        $this->assertDatabaseHas('pending_registrations', [
            'token' => $registrationToken,
            'status' => PendingRegistration::STATUS_COMPLETED,
            'user_id' => $user->id,
        ]);
    }
}
