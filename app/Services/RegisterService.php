<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\PendingRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RegisterService
{
    private const PENDING_TTL_HOURS = 24;

    public function __construct(
        private readonly StripeService $stripe,
    ) {}

    /**
     * Guarda el alta como pendiente. NO crea User ni da acceso.
     *
     * @param  array{
     *     name: string,
     *     email: string,
     *     password: string,
     *     business_name: string,
     *     phone: string,
     *     needs_invoice?: bool,
     *     rfc?: string|null,
     *     legal_name?: string|null,
     *     tax_regime?: string|null,
     *     tax_zip?: string|null,
     *     cfdi_use?: string|null
     * }  $data
     */
    public function createPending(array $data): PendingRegistration
    {
        if (User::query()->where('email', $data['email'])->exists()) {
            throw new HttpException(422, 'El correo ya está registrado.');
        }

        $payload = [
            'password' => Hash::make($data['password']),
            'name' => $data['name'],
            'business_name' => $data['business_name'],
            'phone' => $data['phone'],
            'needs_invoice' => $data['needs_invoice'] ?? false,
            'rfc' => $data['rfc'] ?? null,
            'legal_name' => $data['legal_name'] ?? null,
            'tax_regime' => $data['tax_regime'] ?? null,
            'tax_zip' => $data['tax_zip'] ?? null,
            'cfdi_use' => $data['cfdi_use'] ?? null,
            'status' => PendingRegistration::STATUS_PENDING,
            'expires_at' => now()->addHours(self::PENDING_TTL_HOURS),
            'completed_at' => null,
            'user_id' => null,
        ];

        $existing = PendingRegistration::query()
            ->where('email', $data['email'])
            ->whereIn('status', [
                PendingRegistration::STATUS_PENDING,
                PendingRegistration::STATUS_CHECKOUT,
            ])
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if ($existing) {
            // Si ya inició checkout en Stripe, conserva customer/sub para reutilizar.
            $existing->fill($payload);
            if (! $existing->token) {
                $existing->token = (string) Str::uuid();
            }
            $existing->save();

            return $existing->refresh();
        }

        return PendingRegistration::query()->create([
            ...$payload,
            'email' => $data['email'],
            'token' => (string) Str::uuid(),
        ]);
    }

    public function findOpenByToken(string $token): PendingRegistration
    {
        $pending = PendingRegistration::query()
            ->where('token', $token)
            ->first();

        if (! $pending) {
            throw new HttpException(404, 'Registro pendiente no encontrado.');
        }

        if ($pending->isCompleted()) {
            throw new HttpException(422, 'Este registro ya fue completado. Inicia sesión.');
        }

        if ($pending->isExpired()) {
            $pending->forceFill(['status' => PendingRegistration::STATUS_EXPIRED])->save();

            throw new HttpException(422, 'El registro pendiente expiró. Vuelve a capturar tus datos.');
        }

        return $pending;
    }

    /**
     * Inicia el cobro/suscripción en Stripe sin crear User.
     *
     * @return array{
     *     pending: PendingRegistration,
     *     client_secret: string,
     *     payment_intent_id: string,
     *     subscription_id: string,
     *     plan_id: string,
     *     trial_days: int,
     *     intent_type: 'payment_intent'|'setup_intent',
     *     charge_today: bool
     * }
     *
     * @throws ApiErrorException
     */
    public function startCheckout(PendingRegistration $pending, string $priceId): array
    {
        if (! $this->stripe->isConfiguredPriceId($priceId)) {
            throw new HttpException(422, 'El plan_id no está configurado para ARDOISE.');
        }

        if (User::query()->where('email', $pending->email)->exists()) {
            throw new HttpException(422, 'El correo ya está registrado.');
        }

        $subscription = $this->stripe->createSubscriptionForPending($pending, $priceId);
        $secrets = $this->stripe->extractSubscriptionClientSecret($subscription);

        if ($secrets === null) {
            throw new HttpException(
                502,
                'La suscripción se creó pero Stripe no devolvió client_secret. Revisa el trial o el price en Stripe.',
            );
        }

        $pending->forceFill([
            'stripe_customer_id' => $pending->stripe_customer_id,
            'stripe_subscription_id' => $subscription->id,
            'stripe_price_id' => $priceId,
            'status' => PendingRegistration::STATUS_CHECKOUT,
        ])->save();

        // Refrescar customer id por si se creó en StripeService
        $pending->refresh();

        $intentType = $secrets['intent_type']
            ?? $this->stripe->detectIntentType($secrets['client_secret']);
        $trialDays = $this->stripe->trialDays();

        return [
            'pending' => $pending,
            'client_secret' => $secrets['client_secret'],
            'payment_intent_id' => $secrets['payment_intent_id'],
            'subscription_id' => $secrets['subscription_id'],
            'plan_id' => $priceId,
            'trial_days' => $trialDays,
            'intent_type' => $intentType,
            'charge_today' => $trialDays <= 0,
        ];
    }

    /**
     * Crea User + Negocio solo si el pago/setup de Stripe ya quedó confirmado.
     *
     * @return array{user: User, negocio: Negocio, pending: PendingRegistration}
     *
     * @throws ApiErrorException
     */
    public function complete(PendingRegistration $pending): array
    {
        if ($pending->isCompleted()) {
            $user = User::query()->findOrFail($pending->user_id);
            $negocio = $user->negocio;

            if (! $negocio) {
                throw new HttpException(422, 'El usuario no tiene negocio asociado.');
            }

            return [
                'user' => $user,
                'negocio' => $negocio,
                'pending' => $pending,
            ];
        }

        if ($pending->isExpired()) {
            throw new HttpException(422, 'El registro pendiente expiró. Vuelve a capturar tus datos.');
        }

        if (! $pending->stripe_subscription_id) {
            throw new HttpException(422, 'Aún no se ha iniciado el pago. Selecciona un plan primero.');
        }

        if (! $this->stripe->isSubscriptionReadyForRegistration($pending->stripe_subscription_id)) {
            throw new HttpException(422, 'El pago aún no se ha confirmado. Completa el pago e inténtalo de nuevo.');
        }

        return $this->finalizePending($pending);
    }

    /**
     * Finaliza desde webhook si el pago ya quedó en estado válido.
     *
     * @throws ApiErrorException
     */
    public function completeFromStripeSubscription(string $stripeSubscriptionId): ?User
    {
        $pending = PendingRegistration::query()
            ->where('stripe_subscription_id', $stripeSubscriptionId)
            ->whereIn('status', [
                PendingRegistration::STATUS_PENDING,
                PendingRegistration::STATUS_CHECKOUT,
            ])
            ->first();

        if (! $pending || $pending->isExpired()) {
            return null;
        }

        if (! $this->stripe->isSubscriptionReadyForRegistration($stripeSubscriptionId)) {
            return null;
        }

        return $this->finalizePending($pending)['user'];
    }

    /**
     * @return array{user: User, negocio: Negocio, pending: PendingRegistration}
     *
     * @throws ApiErrorException
     */
    private function finalizePending(PendingRegistration $pending): array
    {
        return DB::transaction(function () use ($pending) {
            $locked = PendingRegistration::query()
                ->whereKey($pending->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isCompleted()) {
                $user = User::query()->findOrFail($locked->user_id);

                return [
                    'user' => $user,
                    'negocio' => $user->negocio()->firstOrFail(),
                    'pending' => $locked,
                ];
            }

            if (User::query()->where('email', $locked->email)->exists()) {
                throw new HttpException(422, 'El correo ya está registrado.');
            }

            // password en pending ya está hasheado; el cast `hashed` de User no lo vuelve a hashear.
            $user = User::query()->create([
                'name' => $locked->name,
                'email' => $locked->email,
                'password' => $locked->password,
                'stripe_customer_id' => $locked->stripe_customer_id,
            ]);

            $negocio = $user->negocio()->create([
                'name' => $locked->business_name,
                'phone' => $locked->phone,
                'needs_invoice' => $locked->needs_invoice,
                'rfc' => $locked->rfc,
                'legal_name' => $locked->legal_name,
                'tax_regime' => $locked->tax_regime,
                'tax_zip' => $locked->tax_zip,
                'cfdi_use' => $locked->cfdi_use,
            ]);

            if ($locked->stripe_customer_id) {
                $this->stripe->attachUserToCustomer($locked->stripe_customer_id, $user);
            }

            if ($locked->stripe_subscription_id) {
                $this->stripe->attachUserToSubscription($locked->stripe_subscription_id, $user);
            }

            $locked->forceFill([
                'status' => PendingRegistration::STATUS_COMPLETED,
                'completed_at' => now(),
                'user_id' => $user->id,
            ])->save();

            return [
                'user' => $user->refresh(),
                'negocio' => $negocio->refresh(),
                'pending' => $locked->refresh(),
            ];
        });
    }
}
