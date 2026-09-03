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
use Stripe\Invoice as StripeInvoice;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Stripe\Subscription as StripeSubscription;
use Stripe\Webhook;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
     * Expands para Billing API Basil+ (confirmation_secret) y trial (setup intent).
     * NO expandir latest_invoice.payment_intent: en Basil ese campo ya no existe y rompe el request.
     *
     * @return list<string>
     */
    private function subscriptionSecretExpands(): array
    {
        return [
            'latest_invoice.confirmation_secret',
            'pending_setup_intent',
        ];
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
                try {
                    $existing = $this->client()->subscriptions->retrieve(
                        $pending->stripe_subscription_id,
                        ['expand' => $this->subscriptionSecretExpands()],
                    );
                } catch (ApiErrorException $e) {
                    Log::warning('No se pudo reutilizar suscripción pending', [
                        'subscription_id' => $pending->stripe_subscription_id,
                        'message' => $e->getMessage(),
                    ]);
                    $existing = null;
                }

                if ($existing !== null) {
                    if (in_array($existing->status, ['trialing', 'active', 'past_due'], true)) {
                        return $existing;
                    }

                    // Incomplete reusable solo si aún podemos obtener client_secret.
                    if ($existing->status === 'incomplete'
                        && $this->extractSubscriptionClientSecret($existing) !== null
                    ) {
                        return $existing;
                    }

                    // Incomplete “rota” (sin secret): cancelar y crear una nueva.
                    if (in_array($existing->status, ['incomplete', 'incomplete_expired'], true)) {
                        try {
                            $this->client()->subscriptions->cancel($existing->id);
                        } catch (ApiErrorException $e) {
                            Log::warning('No se pudo cancelar suscripción incomplete previa', [
                                'subscription_id' => $existing->id,
                                'message' => $e->getMessage(),
                            ]);
                        }

                        $pending->forceFill([
                            'stripe_subscription_id' => null,
                        ])->save();
                    }
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
                'expand' => $this->subscriptionSecretExpands(),
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
                if ($existing->cancel_at_period_end) {
                    $existing = $this->client()->subscriptions->update($existing->id, [
                        'cancel_at_period_end' => false,
                        'expand' => $this->subscriptionSecretExpands(),
                    ]);
                    $this->syncSubscriptionFromStripe($existing);
                }

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
                'expand' => $this->subscriptionSecretExpands(),
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
                ['expand' => $this->subscriptionSecretExpands()],
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
     * Cancela una suscripción al final del periodo (sin reembolso).
     * El acceso se mantiene hasta la fecha de corte.
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

        // Siempre al final del periodo: no hay reembolso ni corte inmediato.
        $this->client()->subscriptions->update($stripeSubscriptionId, [
            'cancel_at_period_end' => true,
        ]);

        Subscription::query()
            ->where('user_id', $user->id)
            ->where('stripe_subscription_id', $stripeSubscriptionId)
            ->update(['cancel_at_period_end' => true]);

        $subscription = $this->client()->subscriptions->retrieve($stripeSubscriptionId);
        $this->syncSubscriptionFromStripe($subscription);

        return $subscription;
    }

    /**
     * Upsert del espejo local a partir del objeto Subscription de Stripe.
     */
    public function syncSubscriptionFromStripe(StripeSubscription $sub): void
    {
        $existing = Subscription::query()
            ->where('stripe_subscription_id', $sub->id)
            ->first();

        $item = null;
        $items = $sub->items ?? null;
        if (is_object($items) && isset($items->data[0])) {
            $item = $items->data[0];
        }
        $priceId = null;
        if (is_object($item) && isset($item->price)) {
            $priceId = is_string($item->price) ? $item->price : ($item->price->id ?? null);
        }
        $priceId = $priceId ?: $existing?->stripe_price_id;

        $userId = $sub->metadata['user_id'] ?? null;

        if (! $userId && $sub->customer) {
            $customerId = is_string($sub->customer) ? $sub->customer : $sub->customer->id;
            $userId = User::query()
                ->where('stripe_customer_id', $customerId)
                ->value('id');
        }

        $userId = $userId ?: $existing?->user_id;

        if (! $userId || ! $priceId) {
            return;
        }

        $periodEnd = $sub->current_period_end
            ?? (is_object($item) ? ($item->current_period_end ?? null) : null);
        $periodStart = $sub->current_period_start
            ?? (is_object($item) ? ($item->current_period_start ?? null) : null);

        $hasAccessCols = \Illuminate\Support\Facades\Schema::hasColumn('subscriptions', 'cancel_at_period_end');

        $cancelAtPeriodEnd = (bool) ($sub->cancel_at_period_end ?? false);
        if ($hasAccessCols && $existing?->cancel_at_period_end && in_array($sub->status, ['canceled', 'unpaid'], true)) {
            $cancelAtPeriodEnd = true;
        }

        $periodEndAt = $periodEnd ? Carbon::createFromTimestamp($periodEnd) : null;
        $periodStartAt = $periodStart ? Carbon::createFromTimestamp($periodStart) : null;
        $accessUntil = $this->resolveAccessUntil(
            $sub->status,
            $periodStartAt,
            $periodEndAt,
            $hasAccessCols ? $existing?->access_until : null,
        );

        $accessFields = [];
        if ($hasAccessCols) {
            $accessFields = [
                'current_period_start' => $periodStartAt,
                'cancel_at_period_end' => $cancelAtPeriodEnd,
                'access_until' => $accessUntil,
            ];
        }

        Subscription::query()->updateOrCreate(
            ['stripe_subscription_id' => $sub->id],
            array_merge([
                'user_id' => (int) $userId,
                'stripe_price_id' => $priceId,
                'status' => $sub->status,
                'current_period_end' => $periodEndAt,
                'trial_ends_at' => isset($sub->trial_end)
                    ? Carbon::createFromTimestamp($sub->trial_end)
                    : null,
                'canceled_at' => isset($sub->canceled_at)
                    ? Carbon::createFromTimestamp($sub->canceled_at)
                    : null,
            ], $accessFields),
        );

        $user = User::query()->find((int) $userId);
        if ($user && in_array($sub->status, ['active', 'trialing'], true)) {
            app(PlanLimitService::class)->applyFromPriceId($user, $priceId, $this);
        }

        if ($user) {
            try {
                app(SubscriptionAccessService::class)->applyForUser($user);
            } catch (\Throwable $e) {
                Log::error('No se pudo actualizar block_POS tras sync de suscripción', [
                    'user_id' => $user->id,
                    'subscription_id' => $sub->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Fecha hasta la que el titular pagó: no se extiende si el cobro falló.
     */
    private function resolveAccessUntil(
        string $status,
        ?Carbon $periodStartAt,
        ?Carbon $periodEndAt,
        mixed $existingAccessUntil,
    ): ?Carbon {
        if (in_array($status, ['active', 'trialing'], true)) {
            return $periodEndAt;
        }

        if ($existingAccessUntil instanceof \DateTimeInterface) {
            return Carbon::instance($existingAccessUntil);
        }

        return $periodStartAt ?? $periodEndAt;
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
     * Planes: basico_mensual | basico_anual | plus_mensual | plus_anual
     *         (+ aliases: standard_*, standart_*, prueba, mensual, anual)
     *
     * @throws \InvalidArgumentException
     */
    public function resolvePriceIdByPlan(string $plan): string
    {
        $plan = strtolower(trim($plan));

        $aliases = [
            'standard_mensual' => 'basico_mensual',
            'standart_mensual' => 'basico_mensual',
            'standard_anual' => 'basico_anual',
            'standart_anual' => 'basico_anual',
            'plus' => 'plus_mensual',
        ];
        $plan = $aliases[$plan] ?? $plan;

        $map = [
            'prueba' => config('services.stripe.price_prueba'),
            'mensual' => config('services.stripe.price_mensual') ?: config('services.stripe.price_basico_mensual'),
            'anual' => config('services.stripe.price_anual') ?: config('services.stripe.price_basico_anual'),
            'basico_mensual' => config('services.stripe.price_basico_mensual'),
            'basico_anual' => config('services.stripe.price_basico_anual'),
            'plus_mensual' => config('services.stripe.price_plus_mensual'),
            'plus_anual' => config('services.stripe.price_plus_anual'),
        ];

        if (! array_key_exists($plan, $map)) {
            throw new \InvalidArgumentException(
                'Plan inválido. Usa: basico_mensual, basico_anual, plus_mensual o plus_anual.',
            );
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
     * @return array<int, array{plan: string, price_id: string, tier: string}>
     */
    public function configuredPlans(): array
    {
        $plans = [];
        $seenPrices = [];

        foreach ([
            'basico_mensual',
            'basico_anual',
            'plus_mensual',
            'plus_anual',
            'prueba',
            'mensual',
            'anual',
        ] as $plan) {
            try {
                $priceId = $this->resolvePriceIdByPlan($plan);
            } catch (\InvalidArgumentException) {
                continue;
            }

            if (isset($seenPrices[$priceId])) {
                continue;
            }

            $seenPrices[$priceId] = true;
            $plans[] = [
                'plan' => $plan,
                'price_id' => $priceId,
                'tier' => app(PlanLimitService::class)->tierForPlan($plan),
            ];
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
     * client_secret de la suscripción.
     *
     * Stripe PHP SDK v21+ usa API Basil: Invoice ya NO tiene payment_intent.
     * Hay que usar latest_invoice.confirmation_secret.client_secret.
     *
     * @return array{client_secret: string, payment_intent_id: string, subscription_id: string, intent_type: string}|null
     */
    public function extractSubscriptionClientSecret(StripeSubscription $subscription): ?array
    {
        try {
            $subscription = $this->client()->subscriptions->retrieve($subscription->id, [
                'expand' => $this->subscriptionSecretExpands(),
            ]);
        } catch (ApiErrorException $e) {
            Log::warning('No se pudo rehidratar suscripción para client_secret', [
                'subscription_id' => $subscription->id,
                'message' => $e->getMessage(),
            ]);
        }

        $invoice = $subscription->latest_invoice ?? null;
        $invoiceId = is_object($invoice) ? ($invoice->id ?? null) : (is_string($invoice) ? $invoice : null);

        if (is_string($invoiceId) && $invoiceId !== '') {
            // Solo confirmation_secret: expandir payment_intent rompe en Basil.
            try {
                $invoice = $this->client()->invoices->retrieve($invoiceId, [
                    'expand' => ['confirmation_secret'],
                ]);
            } catch (ApiErrorException $e) {
                Log::warning('No se pudo rehidratar invoice (confirmation_secret)', [
                    'invoice_id' => $invoiceId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // 1) Basil+: confirmation_secret (prioridad)
        $fromConfirmation = $this->secretFromConfirmationSecret(
            is_object($invoice) ? ($invoice->confirmation_secret ?? null) : null,
            $subscription->id,
        );
        if ($fromConfirmation !== null) {
            return $fromConfirmation;
        }

        // 2) Trial: pending_setup_intent
        $setupIntent = $subscription->pending_setup_intent ?? null;
        if (is_string($setupIntent) && $setupIntent !== '') {
            try {
                $setupIntent = $this->client()->setupIntents->retrieve($setupIntent);
            } catch (ApiErrorException $e) {
                Log::warning('No se pudo rehidratar SetupIntent de suscripción', [
                    'setup_intent_id' => $setupIntent,
                    'message' => $e->getMessage(),
                ]);
                $setupIntent = null;
            }
        }

        if (is_object($setupIntent) && ! empty($setupIntent->client_secret)) {
            return [
                'client_secret' => $setupIntent->client_secret,
                'payment_intent_id' => $setupIntent->id,
                'subscription_id' => $subscription->id,
                'intent_type' => 'setup_intent',
            ];
        }

        // 3) Basil payments[] → payment_intent embebido
        if (is_string($invoiceId) && $invoiceId !== '') {
            try {
                $invoiceWithPayments = $this->client()->invoices->retrieve($invoiceId, [
                    'expand' => ['payments.data.payment.payment_intent'],
                ]);
                $payments = $invoiceWithPayments->payments->data ?? [];
                foreach ($payments as $paymentRow) {
                    $pi = $paymentRow->payment->payment_intent ?? null;
                    if (is_string($pi) && $pi !== '') {
                        $pi = $this->client()->paymentIntents->retrieve($pi);
                    }
                    if (is_object($pi) && ! empty($pi->client_secret)) {
                        return [
                            'client_secret' => $pi->client_secret,
                            'payment_intent_id' => $pi->id,
                            'subscription_id' => $subscription->id,
                            'intent_type' => 'payment_intent',
                        ];
                    }
                }
            } catch (ApiErrorException $e) {
                Log::warning('No se pudo leer payments de invoice', [
                    'invoice_id' => $invoiceId,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // 4) API antigua (pre-Basil): payment_intent directo
        if (is_object($invoice)) {
            $paymentIntent = $invoice->payment_intent ?? null;
            if (is_string($paymentIntent) && $paymentIntent !== '') {
                try {
                    $paymentIntent = $this->client()->paymentIntents->retrieve($paymentIntent);
                } catch (ApiErrorException) {
                    $paymentIntent = null;
                }
            }
            if (is_object($paymentIntent) && ! empty($paymentIntent->client_secret)) {
                return [
                    'client_secret' => $paymentIntent->client_secret,
                    'payment_intent_id' => $paymentIntent->id,
                    'subscription_id' => $subscription->id,
                    'intent_type' => 'payment_intent',
                ];
            }
        }

        Log::warning('Suscripción sin client_secret usable', [
            'subscription_id' => $subscription->id,
            'status' => $subscription->status ?? null,
            'invoice_id' => $invoiceId,
            'has_confirmation_secret' => is_object($invoice) && ! empty($invoice->confirmation_secret ?? null),
            'has_pending_setup_intent' => (bool) ($subscription->pending_setup_intent ?? null),
            'trial_days' => $this->trialDays(),
        ]);

        return null;
    }

    /**
     * @return array{client_secret: string, payment_intent_id: string, subscription_id: string, intent_type: string}|null
     */
    private function secretFromConfirmationSecret(mixed $confirmationSecret, string $subscriptionId): ?array
    {
        if (! is_object($confirmationSecret) || empty($confirmationSecret->client_secret)) {
            return null;
        }

        $secret = (string) $confirmationSecret->client_secret;
        $intentType = $this->detectIntentType($secret);
        $intentId = $this->intentIdFromClientSecret($secret) ?? $subscriptionId;

        return [
            'client_secret' => $secret,
            'payment_intent_id' => $intentId,
            'subscription_id' => $subscriptionId,
            'intent_type' => $intentType,
        ];
    }

    /**
     * Invoices Stripe del customer del usuario (mensuales / anuales).
     *
     * @return array{invoices: list<array<string, mixed>>, has_more: bool}
     *
     * @throws ApiErrorException
     */
    public function listInvoicesForUser(User $user, int $limit = 24, ?string $startingAfter = null): array
    {
        $customerId = $user->stripe_customer_id;
        if (! is_string($customerId) || $customerId === '') {
            return [
                'invoices' => [],
                'has_more' => false,
            ];
        }

        $limit = max(1, min(100, $limit));
        $params = [
            'customer' => $customerId,
            'limit' => $limit,
            // API Basil: invoice.charge ya no existe. Reembolsos viven en payments → PI → charge.
            'expand' => [
                'data.payments.data.payment.payment_intent.latest_charge',
            ],
        ];
        if (is_string($startingAfter) && $startingAfter !== '') {
            $params['starting_after'] = $startingAfter;
        }

        try {
            $collection = $this->client()->invoices->all($params);
        } catch (ApiErrorException $e) {
            Log::warning('Stripe invoices expand payments falló, reintento sin expand', [
                'message' => $e->getMessage(),
            ]);
            unset($params['expand']);
            $collection = $this->client()->invoices->all($params);
        }

        $invoices = [];
        foreach ($collection->data ?? [] as $invoice) {
            if (! $invoice instanceof StripeInvoice && ! is_object($invoice)) {
                continue;
            }
            $invoices[] = $this->hydrateInvoiceRefunds($invoice, $this->mapInvoice($invoice));
        }

        return [
            'invoices' => $invoices,
            'has_more' => (bool) ($collection->has_more ?? false),
        ];
    }

    /**
     * Detalle de un invoice (valida que pertenezca al customer del usuario).
     *
     * @return array<string, mixed>
     *
     * @throws ApiErrorException
     */
    public function getInvoiceForUser(User $user, string $invoiceId): array
    {
        $customerId = $user->stripe_customer_id;
        if (! is_string($customerId) || $customerId === '') {
            throw new HttpException(404, 'No hay facturas asociadas a tu cuenta.');
        }

        try {
            $invoice = $this->client()->invoices->retrieve($invoiceId, [
                'expand' => [
                    'payments.data.payment.payment_intent.latest_charge',
                    'payments.data.payment.payment_intent.latest_charge.refunds',
                ],
            ]);
        } catch (ApiErrorException $e) {
            Log::warning('Stripe invoice retrieve expand payments falló, reintento simple', [
                'invoice_id' => $invoiceId,
                'message' => $e->getMessage(),
            ]);
            $invoice = $this->client()->invoices->retrieve($invoiceId);
        }
        $invoiceCustomer = is_string($invoice->customer ?? null)
            ? $invoice->customer
            : (is_object($invoice->customer ?? null) ? (string) ($invoice->customer->id ?? '') : '');

        if ($invoiceCustomer === '' || $invoiceCustomer !== $customerId) {
            throw new HttpException(404, 'Factura no encontrada.');
        }

        return $this->hydrateInvoiceRefunds($invoice, $this->mapInvoice($invoice));
    }

    /**
     * @param  StripeInvoice|object  $invoice
     * @return array<string, mixed>
     */
    public function mapInvoice(object $invoice): array
    {
        $amountDue = (int) ($invoice->amount_due ?? 0);
        $amountPaid = (int) ($invoice->amount_paid ?? 0);
        $currency = strtolower((string) ($invoice->currency ?? config('services.stripe.currency', 'mxn')));
        $stripeStatus = strtolower((string) ($invoice->status ?? ''));

        $lineDescription = null;
        $interval = null;
        $priceId = null;
        $lines = $invoice->lines->data ?? [];
        if (is_array($lines) && isset($lines[0]) && is_object($lines[0])) {
            $line = $lines[0];
            $lineDescription = $line->description ?? null;
            $price = $line->price ?? $line->pricing?->price_details?->price ?? null;
            if (is_object($price)) {
                $priceId = $price->id ?? null;
                $interval = $price->recurring->interval ?? null;
            } elseif (is_string($price)) {
                $priceId = $price;
            }
        }

        $planKey = is_string($priceId) ? $this->resolvePlanByPriceId($priceId) : null;
        $billingPeriod = match ($interval) {
            'year' => 'anual',
            'month' => 'mensual',
            default => $interval,
        };

        $refundInfo = $this->resolveInvoiceRefundInfo($invoice);
        $display = $this->resolveInvoiceDisplayStatus($stripeStatus, $refundInfo);

        return [
            'id' => (string) $invoice->id,
            'number' => $invoice->number ?? null,
            // Estado crudo de Stripe (draft|open|paid|uncollectible|void)
            'status' => $stripeStatus,
            'status_label' => $display['status_label'],
            // Estado para UI (incluye reembolsos)
            'display_status' => $display['display_status'],
            'estatus' => $display['estatus'],
            'currency' => $currency,
            'amount_due_cents' => $amountDue,
            'amount_paid_cents' => $amountPaid,
            'amount_due' => round($amountDue / 100, 2),
            'amount_paid' => round($amountPaid / 100, 2),
            'amount_refunded_cents' => $refundInfo['amount_refunded_cents'],
            'amount_refunded' => round($refundInfo['amount_refunded_cents'] / 100, 2),
            'refunded' => $refundInfo['fully_refunded'],
            'partially_refunded' => $refundInfo['partially_refunded'],
            'refund_pending' => $refundInfo['refund_pending'],
            'created_at' => isset($invoice->created)
                ? Carbon::createFromTimestamp((int) $invoice->created)->toIso8601String()
                : null,
            'period_start' => isset($invoice->period_start)
                ? Carbon::createFromTimestamp((int) $invoice->period_start)->toIso8601String()
                : null,
            'period_end' => isset($invoice->period_end)
                ? Carbon::createFromTimestamp((int) $invoice->period_end)->toIso8601String()
                : null,
            'paid_at' => isset($invoice->status_transitions->paid_at)
                ? Carbon::createFromTimestamp((int) $invoice->status_transitions->paid_at)->toIso8601String()
                : null,
            'voided_at' => isset($invoice->status_transitions->voided_at)
                ? Carbon::createFromTimestamp((int) $invoice->status_transitions->voided_at)->toIso8601String()
                : null,
            'description' => $lineDescription ?? ($invoice->description ?? null),
            'plan' => $planKey,
            'billing_period' => $billingPeriod,
            'invoice_pdf' => $invoice->invoice_pdf ?? null,
            'hosted_invoice_url' => $invoice->hosted_invoice_url ?? null,
        ];
    }

    /**
     * Stripe Invoice.status no incluye "refunded". Se deriva del charge / credit notes.
     *
     * @return array{
     *     amount_refunded_cents: int,
     *     fully_refunded: bool,
     *     partially_refunded: bool,
     *     refund_pending: bool
     * }
     */
    private function resolveInvoiceRefundInfo(object $invoice): array
    {
        $amountPaid = (int) ($invoice->amount_paid ?? 0);
        $creditNotes = (int) ($invoice->post_payment_credit_notes_amount ?? 0);

        $amountRefunded = $creditNotes;
        $refundPending = false;

        $charge = $invoice->charge ?? null;
        $this->accumulateChargeRefunds($charge, $amountRefunded, $refundPending);

        $paymentRows = $invoice->payments->data ?? [];
        if (is_array($paymentRows)) {
            foreach ($paymentRows as $row) {
                if (! is_object($row)) {
                    continue;
                }

                $payment = $row->payment ?? null;
                $paymentIntent = is_object($payment) ? ($payment->payment_intent ?? null) : null;
                $chargeFromPi = null;
                if (is_object($paymentIntent)) {
                    $chargeFromPi = $paymentIntent->latest_charge
                        ?? ($paymentIntent->charges->data[0] ?? null);
                }

                $this->accumulateChargeRefunds($chargeFromPi, $amountRefunded, $refundPending);
            }
        }

        $fullyRefunded = $amountPaid > 0 && $amountRefunded >= $amountPaid;
        if (is_object($charge) && (bool) ($charge->refunded ?? false)) {
            $fullyRefunded = true;
        }

        $partiallyRefunded = $amountRefunded > 0 && ! $fullyRefunded;

        return [
            'amount_refunded_cents' => $amountRefunded,
            'fully_refunded' => $fullyRefunded,
            'partially_refunded' => $partiallyRefunded,
            'refund_pending' => $refundPending,
        ];
    }

    /**
     * Si el expand no trajo el cargo, consulta refunds por payment_intent (API Basil).
     *
     * @param  array<string, mixed>  $mapped
     * @return array<string, mixed>
     */
    private function hydrateInvoiceRefunds(object $invoice, array $mapped): array
    {
        if (($mapped['display_status'] ?? '') !== 'paid') {
            return $mapped;
        }

        if ((int) ($mapped['amount_refunded_cents'] ?? 0) > 0) {
            return $mapped;
        }

        $intentIds = $this->paymentIntentIdsFromInvoice($invoice);
        if ($intentIds === []) {
            return $mapped;
        }

        $amountRefunded = 0;
        $refundPending = false;

        foreach ($intentIds as $intentId) {
            try {
                $refunds = $this->client()->refunds->all([
                    'payment_intent' => $intentId,
                    'limit' => 20,
                ]);
            } catch (ApiErrorException $e) {
                Log::warning('No se pudieron listar refunds del payment_intent', [
                    'payment_intent' => $intentId,
                    'invoice_id' => $invoice->id ?? null,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($refunds->data ?? [] as $refund) {
                if (! is_object($refund)) {
                    continue;
                }
                $status = strtolower((string) ($refund->status ?? ''));
                if ($status === 'canceled' || $status === 'failed') {
                    continue;
                }
                $amountRefunded += (int) ($refund->amount ?? 0);
                if (in_array($status, ['pending', 'requires_action'], true)) {
                    $refundPending = true;
                }
            }
        }

        if ($amountRefunded <= 0 && ! $refundPending) {
            return $mapped;
        }

        $amountPaidCents = (int) ($mapped['amount_paid_cents'] ?? 0);
        $refundInfo = [
            'amount_refunded_cents' => $amountRefunded,
            'fully_refunded' => $amountPaidCents > 0 && $amountRefunded >= $amountPaidCents,
            'partially_refunded' => $amountRefunded > 0 && $amountRefunded < $amountPaidCents,
            'refund_pending' => $refundPending,
        ];
        $display = $this->resolveInvoiceDisplayStatus('paid', $refundInfo);

        return array_merge($mapped, [
            'amount_refunded_cents' => $refundInfo['amount_refunded_cents'],
            'amount_refunded' => round($refundInfo['amount_refunded_cents'] / 100, 2),
            'refunded' => $refundInfo['fully_refunded'],
            'partially_refunded' => $refundInfo['partially_refunded'],
            'refund_pending' => $refundInfo['refund_pending'],
            'status_label' => $display['status_label'],
            'display_status' => $display['display_status'],
            'estatus' => $display['estatus'],
        ]);
    }

    /**
     * @return list<string>
     */
    private function paymentIntentIdsFromInvoice(object $invoice): array
    {
        $ids = [];
        $rows = $invoice->payments->data ?? [];
        if (! is_array($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }
            $payment = $row->payment ?? null;
            $pi = is_object($payment) ? ($payment->payment_intent ?? null) : null;
            if (is_object($pi) && isset($pi->id)) {
                $ids[] = (string) $pi->id;
            } elseif (is_string($pi) && str_starts_with($pi, 'pi_')) {
                $ids[] = $pi;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  mixed  $charge
     */
    private function accumulateChargeRefunds(mixed $charge, int &$amountRefunded, bool &$refundPending): void
    {
        if (! is_object($charge)) {
            return;
        }

        $amountRefunded = max($amountRefunded, (int) ($charge->amount_refunded ?? 0));
        if ((bool) ($charge->refunded ?? false)) {
            $amountPaidOnCharge = (int) ($charge->amount ?? $charge->amount_captured ?? 0);
            if ($amountPaidOnCharge > 0) {
                $amountRefunded = max($amountRefunded, $amountPaidOnCharge);
            }
        }

        $refunds = $charge->refunds->data ?? null;
        if (! is_array($refunds)) {
            return;
        }

        foreach ($refunds as $refund) {
            if (! is_object($refund)) {
                continue;
            }
            $refundStatus = strtolower((string) ($refund->status ?? ''));
            if (in_array($refundStatus, ['pending', 'requires_action'], true)) {
                $refundPending = true;
            }
        }
    }

    /**
     * @param  array{
     *     amount_refunded_cents: int,
     *     fully_refunded: bool,
     *     partially_refunded: bool,
     *     refund_pending: bool
     * }  $refundInfo
     * @return array{display_status: string, status_label: string, estatus: string}
     */
    private function resolveInvoiceDisplayStatus(string $stripeStatus, array $refundInfo): array
    {
        if ($refundInfo['refund_pending']) {
            return [
                'display_status' => 'refund_pending',
                'status_label' => 'En proceso de reembolso',
                'estatus' => 'En proceso de reembolso',
            ];
        }

        if ($refundInfo['fully_refunded']) {
            return [
                'display_status' => 'refunded',
                'status_label' => 'Reembolsada',
                'estatus' => 'Reembolsada',
            ];
        }

        if ($refundInfo['partially_refunded']) {
            return [
                'display_status' => 'partially_refunded',
                'status_label' => 'Reembolso parcial',
                'estatus' => 'Reembolso parcial',
            ];
        }

        return match ($stripeStatus) {
            'draft' => [
                'display_status' => 'draft',
                'status_label' => 'Borrador',
                'estatus' => 'Borrador',
            ],
            'open' => [
                'display_status' => 'open',
                'status_label' => 'En proceso de pago',
                'estatus' => 'En proceso de pago',
            ],
            'paid' => [
                'display_status' => 'paid',
                'status_label' => 'Pagada',
                'estatus' => 'Pagada',
            ],
            'void' => [
                'display_status' => 'void',
                'status_label' => 'Anulada',
                'estatus' => 'Anulada',
            ],
            'uncollectible' => [
                'display_status' => 'uncollectible',
                'status_label' => 'Incobrable',
                'estatus' => 'Incobrable',
            ],
            default => [
                'display_status' => $stripeStatus !== '' ? $stripeStatus : 'unknown',
                'status_label' => $stripeStatus !== '' ? ucfirst($stripeStatus) : 'Desconocido',
                'estatus' => $stripeStatus !== '' ? ucfirst($stripeStatus) : 'Desconocido',
            ],
        };
    }

    /**
     * @return 'payment_intent'|'setup_intent'
     */
    public function detectIntentType(string $clientSecret): string
    {
        if (str_starts_with($clientSecret, 'seti_')) {
            return 'setup_intent';
        }

        return 'payment_intent';
    }

    private function intentIdFromClientSecret(string $clientSecret): ?string
    {
        $pos = strpos($clientSecret, '_secret');

        return $pos === false ? null : substr($clientSecret, 0, $pos);
    }
}
