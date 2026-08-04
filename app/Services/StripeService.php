<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PendingRegistration;
use App\Models\Subscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
     * Customer Stripe ligado a un registro pendiente (aún sin User).
     *
     * @throws ApiErrorException
     */
    public function getOrCreateCustomerForPending(PendingRegistration $pending): string
    {
        if ($pending->stripe_customer_id) {
            return $pending->stripe_customer_id;
        }

        $customer = $this->client()->customers->create([
            'email' => $pending->email,
            'name' => $pending->name,
            'metadata' => [
                'pending_registration_id' => (string) $pending->id,
                'pending_registration_token' => $pending->token,
            ],
        ]);

        $pending->forceFill([
            'stripe_customer_id' => $customer->id,
        ])->save();

        return $customer->id;
    }

    /**
     * Suscripción incomplete para onboarding sin User.
     *
     * @throws ApiErrorException
     */
    public function createSubscriptionForPending(
        PendingRegistration $pending,
        string $priceId,
    ): StripeSubscription {
        return DB::transaction(function () use ($pending, $priceId) {
            PendingRegistration::query()->whereKey($pending->id)->lockForUpdate()->first();
            $pending->refresh();

            if (
                $pending->stripe_subscription_id
                && $pending->stripe_price_id === $priceId
            ) {
                $existing = $this->client()->subscriptions->retrieve(
                    $pending->stripe_subscription_id,
                    ['expand' => ['latest_invoice.payment_intent', 'pending_setup_intent']],
                );

                if (in_array($existing->status, ['incomplete', 'trialing', 'active', 'past_due'], true)) {
                    return $existing;
                }
            }

            $customerId = $this->getOrCreateCustomerForPending($pending);
            $trialDays = $this->trialDays();

            $params = [
                'customer' => $customerId,
                'items' => [
                    ['price' => $priceId],
                ],
                'payment_behavior' => 'default_incomplete',
                'payment_settings' => [
                    'save_default_payment_method' => 'on_subscription',
                ],
                'expand' => ['latest_invoice.payment_intent', 'pending_setup_intent'],
                'metadata' => [
                    'pending_registration_id' => (string) $pending->id,
                    'pending_registration_token' => $pending->token,
                ],
            ];

            if ($trialDays > 0) {
                $params['trial_period_days'] = $trialDays;
                $params['trial_settings'] = [
                    'end_behavior' => [
                        'missing_payment_method' => 'cancel',
                    ],
                ];
            }

            $subscription = $this->client()->subscriptions->create($params);

            $pending->forceFill([
                'stripe_customer_id' => $customerId,
                'stripe_subscription_id' => $subscription->id,
                'stripe_price_id' => $priceId,
                'status' => PendingRegistration::STATUS_CHECKOUT,
            ])->save();

            return $subscription;
        });
    }

    /**
     * True si la suscripción ya permite crear la cuenta (pago/setup confirmado).
     *
     * @throws ApiErrorException
     */
    public function isSubscriptionReadyForRegistration(string $stripeSubscriptionId): bool
    {
        $subscription = $this->client()->subscriptions->retrieve($stripeSubscriptionId);

        return in_array($subscription->status, ['trialing', 'active'], true);
    }

    /**
     * Vincula metadata del Customer a un User recién creado.
     *
     * @throws ApiErrorException
     */
    public function attachUserToCustomer(string $stripeCustomerId, User $user): void
    {
        try {
            $this->client()->customers->update($stripeCustomerId, [
                'metadata' => [
                    'user_id' => (string) $user->id,
                ],
            ]);
        } catch (ApiErrorException $e) {
            Log::warning('No se pudo actualizar metadata del customer Stripe', [
                'customer_id' => $stripeCustomerId,
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);
        }

        if (! $user->stripe_customer_id) {
            $user->forceFill(['stripe_customer_id' => $stripeCustomerId])->save();
        }
    }

    /**
     * Vincula metadata de la suscripción y crea el espejo local.
     *
     * @throws ApiErrorException
     */
    public function attachUserToSubscription(string $stripeSubscriptionId, User $user): void
    {
        $subscription = $this->client()->subscriptions->update($stripeSubscriptionId, [
            'metadata' => [
                'user_id' => (string) $user->id,
            ],
        ]);

        // Rehidratar con items/price para sync
        $subscription = $this->client()->subscriptions->retrieve($subscription->id);
        $this->syncSubscriptionFromStripe($subscription);
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
     * Obtiene un precio por ID (incluye product expandido).
     *
     * @throws ApiErrorException
     */
    public function retrievePrice(string $priceId): \Stripe\Price
    {
        return $this->client()->prices->retrieve($priceId, [
            'expand' => ['product'],
        ]);
    }

    /**
     * Crea una suscripción incomplete y persiste el espejo local.
     * Aplica trial según config('services.stripe.trial_days') si es > 0.
     *
     * Idempotente: si el usuario ya tiene una suscripción reusable
     * (incomplete/trialing/active/past_due) del mismo price_id, la reutiliza.
     * Evita duplicados cuando el front llama /payments/intent y /subscriptions.
     *
     * @throws ApiErrorException
     */
    public function createSubscription(User $user, string $priceId): StripeSubscription
    {
        return DB::transaction(function () use ($user, $priceId) {
            // Serializa creaciones concurrentes del mismo usuario
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $existing = $this->findReusableSubscription($user, $priceId);
            if ($existing !== null) {
                return $existing;
            }

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
                'expand' => ['latest_invoice.payment_intent', 'pending_setup_intent'],
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
        });
    }

    /**
     * Busca una suscripción local/Stripe reusable para el mismo usuario + price.
     *
     * @throws ApiErrorException
     */
    private function findReusableSubscription(User $user, string $priceId): ?StripeSubscription
    {
        $reusableStatuses = ['incomplete', 'trialing', 'active', 'past_due'];

        $locals = Subscription::query()
            ->where('user_id', $user->id)
            ->where('stripe_price_id', $priceId)
            ->whereIn('status', $reusableStatuses)
            ->orderByDesc('id')
            ->get();

        $keeper = null;

        foreach ($locals as $local) {
            $stripeSub = $this->client()->subscriptions->retrieve(
                $local->stripe_subscription_id,
                ['expand' => ['latest_invoice.payment_intent', 'pending_setup_intent']],
            );

            if (! in_array($stripeSub->status, $reusableStatuses, true)) {
                $this->syncSubscriptionFromStripe($stripeSub);

                continue;
            }

            if ($keeper === null) {
                $keeper = $stripeSub;
                $this->syncSubscriptionFromStripe($stripeSub);

                continue;
            }

            // Cancelar duplicados extras en Stripe (deja solo la más reciente)
            try {
                $canceled = $this->client()->subscriptions->cancel($stripeSub->id);
                $this->syncSubscriptionFromStripe($canceled);
            } catch (ApiErrorException) {
                // Si ya no se puede cancelar, solo sincroniza estado actual
                $this->syncSubscriptionFromStripe($stripeSub);
            }
        }

        return $keeper;
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

    /**
     * True si el price_id está en STRIPE_PRICE_* del .env.
     */
    public function isConfiguredPriceId(string $priceId): bool
    {
        return collect($this->configuredPlans())
            ->contains(fn (array $plan) => $plan['price_id'] === $priceId);
    }

    /**
     * Resuelve el nombre de plan (prueba|mensual|anual) desde un price_id.
     */
    public function resolvePlanByPriceId(string $priceId): ?string
    {
        foreach ($this->configuredPlans() as $configured) {
            if ($configured['price_id'] === $priceId) {
                return $configured['plan'];
            }
        }

        return null;
    }

    /**
     * client_secret de la suscripción (PaymentIntent o SetupIntent en trial).
     *
     * @return array{client_secret: string, payment_intent_id: string, subscription_id: string}|null
     */
    public function extractSubscriptionClientSecret(StripeSubscription $subscription): ?array
    {
        $invoice = $subscription->latest_invoice;
        $paymentIntent = is_object($invoice) ? ($invoice->payment_intent ?? null) : null;

        if (is_object($paymentIntent) && ! empty($paymentIntent->client_secret)) {
            return [
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id,
                'subscription_id' => $subscription->id,
            ];
        }

        $setupIntent = $subscription->pending_setup_intent ?? null;

        if (is_string($setupIntent) && $setupIntent !== '') {
            $setupIntent = $this->client()->setupIntents->retrieve($setupIntent);
        }

        if (is_object($setupIntent) && ! empty($setupIntent->client_secret)) {
            return [
                'client_secret' => $setupIntent->client_secret,
                // El front espera payment_intent_id; en trial usamos el SetupIntent.
                'payment_intent_id' => $setupIntent->id,
                'subscription_id' => $subscription->id,
            ];
        }

        return null;
    }
}
