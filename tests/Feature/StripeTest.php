<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Subscription as StripeSubscription;
use Tests\TestCase;

class StripeTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_payment_intent_without_token_returns_401(): void
    {
        $this->postJson('/api/payments/intent', [
            'amount' => 1000,
        ])->assertUnauthorized();
    }

    public function test_create_payment_intent_with_invalid_amount_returns_422(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/payments/intent', [
                'amount' => 0,
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_create_payment_intent_with_valid_payload_returns_client_secret(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $intent = PaymentIntent::constructFrom([
            'id' => 'pi_test_123',
            'client_secret' => 'pi_test_123_secret',
            'status' => 'requires_payment_method',
        ]);

        $this->mock(StripeService::class, function (MockInterface $mock) use ($intent) {
            $mock->shouldReceive('createPaymentIntent')
                ->once()
                ->andReturn($intent);
        });

        $this->withToken($token)
            ->postJson('/api/payments/intent', [
                'amount' => 1500,
                'currency' => 'mxn',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_intent_id', 'pi_test_123')
            ->assertJsonPath('data.client_secret', 'pi_test_123_secret');
    }

    public function test_webhook_with_invalid_signature_returns_400(): void
    {
        $this->mock(StripeService::class, function (MockInterface $mock) {
            $mock->shouldReceive('constructWebhookEvent')
                ->once()
                ->andThrow(new SignatureVerificationException('Invalid signature'));
        });

        $this->postJson('/api/stripe/webhook', ['type' => 'payment_intent.succeeded'], [
            'Stripe-Signature' => 't=1,v1=invalid',
        ])
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid Stripe signature.');
    }

    public function test_webhook_payment_intent_succeeded_updates_local_payment(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'stripe_payment_intent_id' => 'pi_test_succeeded',
            'amount' => 2000,
            'currency' => 'mxn',
            'status' => 'requires_payment_method',
        ]);

        $event = Event::constructFrom([
            'id' => 'evt_test_123',
            'type' => 'payment_intent.succeeded',
            'data' => [
                'object' => [
                    'id' => 'pi_test_succeeded',
                    'object' => 'payment_intent',
                    'status' => 'succeeded',
                ],
            ],
        ]);

        $this->mock(StripeService::class, function (MockInterface $mock) use ($event) {
            $mock->shouldReceive('constructWebhookEvent')
                ->once()
                ->andReturn($event);
        });

        $this->call(
            'POST',
            '/api/stripe/webhook',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_Stripe-Signature' => 't=1,v1=test',
            ],
            json_encode(['id' => 'evt_test_123', 'type' => 'payment_intent.succeeded']),
        )
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'stripe_payment_intent_id' => 'pi_test_succeeded',
            'status' => 'succeeded',
        ]);

        $this->assertDatabaseHas('stripe_events', [
            'stripe_event_id' => 'evt_test_123',
            'type' => 'payment_intent.succeeded',
        ]);
    }

    public function test_create_subscription_with_plan_prueba_resolves_price_from_config(): void
    {
        config([
            'services.stripe.price_prueba' => 'price_1TzhHLQMCZvDbFTHiAqpsoOr',
            'services.stripe.trial_days' => 14,
        ]);

        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $subscription = StripeSubscription::constructFrom([
            'id' => 'sub_test_123',
            'status' => 'trialing',
            'trial_end' => now()->addDays(14)->timestamp,
            'latest_invoice' => [
                'payment_intent' => [
                    'id' => 'pi_sub_test',
                    'client_secret' => 'pi_sub_test_secret',
                ],
            ],
        ]);

        $this->mock(StripeService::class, function (MockInterface $mock) use ($subscription) {
            $mock->shouldReceive('resolvePriceIdByPlan')
                ->once()
                ->with('prueba')
                ->andReturn('price_1TzhHLQMCZvDbFTHiAqpsoOr');

            $mock->shouldReceive('createSubscription')
                ->once()
                ->andReturn($subscription);

            $mock->shouldReceive('trialDays')
                ->andReturn(14);
        });

        $this->withToken($token)
            ->postJson('/api/subscriptions', [
                'plan' => 'prueba',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.plan', 'prueba')
            ->assertJsonPath('data.price_id', 'price_1TzhHLQMCZvDbFTHiAqpsoOr')
            ->assertJsonPath('data.subscription_id', 'sub_test_123')
            ->assertJsonPath('data.client_secret', 'pi_sub_test_secret');
    }

    public function test_create_subscription_without_plan_returns_422(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/subscriptions', [])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
