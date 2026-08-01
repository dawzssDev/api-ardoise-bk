<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeService
{
    private ?StripeClient $stripe = null;

    /**
     * Cliente Stripe (lazy: evita fallar al resolver el contenedor sin secret).
     */
    private function client(): StripeClient
    {
        return $this->stripe ??= new StripeClient((string) config('services.stripe.secret'));
    }

    /**
     * Obtiene o crea el Customer en Stripe y persiste el id local.
     *
     * @throws ApiErrorException
     */
    public function getOrCreateCustomer(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = $this->client()->customers->create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
        ]);

        $user->forceFill([
            'stripe_customer_id' => $customer->id,
        ])->save();

        return $customer->id;
    }

    /**
     * Crea un PaymentIntent y registra el pago local.
     *
     * @param  array<string, string>  $metadata
     *
     * @throws ApiErrorException
     */
    public function createPaymentIntent(
        User $user,
        int $amountInCents,
        ?string $currency = null,
        array $metadata = [],
    ): PaymentIntent {
        $currency = strtolower($currency ?: (string) config('services.stripe.currency'));
        $customerId = $this->getOrCreateCustomer($user);

        $paymentIntent = $this->client()->paymentIntents->create([
            'amount' => $amountInCents,
            'currency' => $currency,
            'customer' => $customerId,
            'automatic_payment_methods' => [
                'enabled' => true,
            ],
            'metadata' => array_merge([
                'user_id' => (string) $user->id,
            ], $metadata),
        ]);

        Payment::create([
            'user_id' => $user->id,
            'stripe_payment_intent_id' => $paymentIntent->id,
            'amount' => $amountInCents,
            'currency' => $currency,
            'status' => $paymentIntent->status,
        ]);

        return $paymentIntent;
    }

    /**
     * Lista productos de Stripe.
     *
     * @return array<int, \Stripe\Product>
     *
     * @throws ApiErrorException
     */
    public function listProducts(bool $activeOnly = true): array
    {
        $params = ['limit' => 100];

        if ($activeOnly) {
            $params['active'] = true;
        }

        return $this->client()->products->all($params)->data;
    }

    /**
     * Lista precios de Stripe (opcionalmente filtrados por producto).
     *
     * @return array<int, \Stripe\Price>
     *
     * @throws ApiErrorException
     */
    public function listPrices(?string $productId = null): array
    {
        $params = [
            'limit' => 100,
            'active' => true,
        ];

        if ($productId) {
            $params['product'] = $productId;
        }

        return $this->client()->prices->all($params)->data;
    }

    /**
     * Crea una suscripción incomplete y persiste el espejo local.
     * Aplica trial según config('services.stripe.trial_days') si es > 0.
     *
     * @throws ApiErrorException
     */
    public function createSubscription(User $user, string $priceId): StripeSubscription
    {
        $customerId = $this->getOrCreateCustomer($user);
        $trialDays = max(0, (int) config('services.stripe.trial_days'));

        $params = [
            'customer' => $customerId,
            'items' => [
                ['price' => $priceId],
            ],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription',
            ],
            'expand' => ['latest_invoice.payment_intent'],
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
        ];

        // Periodo de prueba configurable (STRIPE_TRIAL_DAYS=14|7|3|0)
        if ($trialDays > 0) {
            $params['trial_period_days'] = $trialDays;
            $params['trial_settings'] = [
                'end_behavior' => [
                    'missing_payment_method' => 'cancel',
                ],
            ];
        }

        $subscription = $this->client()->subscriptions->create($params);

        $this->syncSubscriptionFromStripe($subscription);

        return $subscription;
    }

    /**
     * Cancela una suscripción (al final del periodo o inmediata).
     *
     * @throws ApiErrorException
     */
    public function cancelSubscription(
        User $user,
        string $stripeSubscriptionId,
        bool $atPeriodEnd = true,
    ): StripeSubscription {
        Subscription::query()
            ->where('user_id', $user->id)
            ->where('stripe_subscription_id', $stripeSubscriptionId)
            ->firstOrFail();

        if ($atPeriodEnd) {
            $subscription = $this->client()->subscriptions->update($stripeSubscriptionId, [
                'cancel_at_period_end' => true,
            ]);
        } else {
            $subscription = $this->client()->subscriptions->cancel($stripeSubscriptionId);
        }

        $this->syncSubscriptionFromStripe($subscription);

        return $subscription;
    }

    /**
     * Upsert del espejo local a partir del objeto Subscription de Stripe.
     */
    public function syncSubscriptionFromStripe(StripeSubscription $sub): void
    {
        $priceId = $sub->items->data[0]->price->id ?? null;
        $userId = $sub->metadata['user_id'] ?? null;

        if (! $userId && $sub->customer) {
            $customerId = is_string($sub->customer) ? $sub->customer : $sub->customer->id;
            $userId = User::query()
                ->where('stripe_customer_id', $customerId)
                ->value('id');
        }

        if (! $userId || ! $priceId) {
            return;
        }

        $periodEnd = $sub->current_period_end
            ?? ($sub->items->data[0]->current_period_end ?? null);

        Subscription::query()->updateOrCreate(
            ['stripe_subscription_id' => $sub->id],
            [
                'user_id' => (int) $userId,
                'stripe_price_id' => $priceId,
                'status' => $sub->status,
                'current_period_end' => $periodEnd
                    ? Carbon::createFromTimestamp($periodEnd)
                    : null,
                'trial_ends_at' => isset($sub->trial_end)
                    ? Carbon::createFromTimestamp($sub->trial_end)
                    : null,
                'canceled_at' => isset($sub->canceled_at)
                    ? Carbon::createFromTimestamp($sub->canceled_at)
                    : null,
            ],
        );
    }

    /**
     * Días de trial configurados (0 = desactivado).
     */
    public function trialDays(): int
    {
        return max(0, (int) config('services.stripe.trial_days'));
    }

    /**
     * Verifica la firma del webhook y construye el evento de Stripe.
     *
     * @throws UnexpectedValueException
     * @throws SignatureVerificationException
     */
    public function constructWebhookEvent(string $payload, string $sigHeader): \Stripe\Event
    {
        return Webhook::constructEvent(
            $payload,
            $sigHeader,
            (string) config('services.stripe.webhook_secret'),
        );
    }

    /**
     * Recupera una suscripción desde Stripe (p. ej. desde invoice).
     *
     * @throws ApiErrorException
     */
    public function retrieveSubscription(string $subscriptionId): StripeSubscription
    {
        return $this->client()->subscriptions->retrieve($subscriptionId);
    }

    /**
     * Resuelve el price_id de Stripe desde el plan del .env.
     * Planes: prueba | mensual | anual
     *
     * @throws \InvalidArgumentException
     */
    public function resolvePriceIdByPlan(string $plan): string
    {
        $plan = strtolower(trim($plan));

        $map = [
            'prueba' => config('services.stripe.price_prueba'),
            'mensual' => config('services.stripe.price_mensual'),
            'anual' => config('services.stripe.price_anual'),
        ];

        if (! array_key_exists($plan, $map)) {
            throw new \InvalidArgumentException('Plan inválido. Usa: prueba, mensual o anual.');
        }

        $priceId = $map[$plan];

        if (! is_string($priceId) || $priceId === '') {
            throw new \InvalidArgumentException("El plan '{$plan}' no tiene price_id configurado en .env.");
        }

        return $priceId;
    }

    /**
     * Planes configurados en .env (solo los que tienen price_id).
     *
     * @return array<int, array{plan: string, price_id: string}>
     */
    public function configuredPlans(): array
    {
        $plans = [];

        foreach (['prueba', 'mensual', 'anual'] as $plan) {
            $priceId = config("services.stripe.price_{$plan}");

            if (is_string($priceId) && $priceId !== '') {
                $plans[] = [
                    'plan' => $plan,
                    'price_id' => $priceId,
                ];
            }
        }

        return $plans;
    }
}
