<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Subscription\CreateSubscriptionRequest;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly StripeService $stripe,
    ) {}

    /**
     * Productos y precios de ARDOISE (solo los price_id del .env).
     */
    public function plans(): JsonResponse
    {
        $configuredPlans = $this->stripe->configuredPlans();

        if ($configuredPlans === []) {
            return response()->json([
                'success' => true,
                'message' => 'ok',
                'data' => [
                    'plans' => [],
                    'configured_plans' => [],
                    'trial_days' => $this->stripe->trialDays(),
                ],
                'errors' => null,
            ]);
        }

        $pricesByProduct = [];
        $productsById = [];
        $enrichedConfigured = [];

        try {
            foreach ($configuredPlans as $configured) {
                $price = $this->stripe->retrievePrice($configured['price_id']);
                $product = $price->product;
                $productId = is_string($product) ? $product : (string) $product->id;

                if (! is_string($product) && ! isset($productsById[$productId])) {
                    $productsById[$productId] = [
                        'id' => (string) $product->id,
                        'name' => $product->name,
                        'description' => $product->description,
                    ];
                }

                // Stripe guarda centavos: 100 = $1.00 MXN
                $unitAmount = (int) ($price->unit_amount ?? 0);
                $interval = $price->recurring?->interval;
                $intervalCount = $price->recurring?->interval_count;

                $pricePayload = [
                    'id' => $price->id,
                    'currency' => $price->currency,
                    'unit_amount' => $unitAmount,
                    'amount' => $unitAmount / 100,
                    'interval' => $interval,
                    'interval_count' => $intervalCount,
                ];

                $pricesByProduct[$productId][] = $pricePayload;

                $enrichedConfigured[] = [
                    'plan' => $configured['plan'],
                    'price_id' => $configured['price_id'],
                    'currency' => $price->currency,
                    'unit_amount' => $unitAmount,
                    'amount' => $unitAmount / 100,
                    'interval' => $interval,
                    'interval_count' => $intervalCount,
                    'product_id' => $productId,
                    'product_name' => is_string($product) ? null : $product->name,
                ];
            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e instanceof ApiErrorException ? 502 : 500);
        }

        $plans = collect($productsById)
            ->map(function (array $product) use ($pricesByProduct) {
                return [
                    'id' => $product['id'],
                    'name' => $product['name'],
                    'description' => $product['description'],
                    'prices' => $pricesByProduct[$product['id']] ?? [],
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'plans' => $plans,
                'configured_plans' => $enrichedConfigured,
                'trial_days' => $this->stripe->trialDays(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Crear suscripción por plan (prueba|mensual|anual) o plan_id (price_xxx).
     */
    public function store(CreateSubscriptionRequest $request): JsonResponse
    {
        $plan = $request->validated('plan');
        $planId = $request->validated('plan_id');

        try {
            if (is_string($planId) && $planId !== '') {
                if (! $this->stripe->isConfiguredPriceId($planId)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'El plan_id no está configurado para ARDOISE.',
                        'data' => null,
                        'errors' => ['plan_id' => ['El plan_id no está permitido.']],
                    ], 422);
                }

                $priceId = $planId;
                $plan = $this->stripe->resolvePlanByPriceId($planId) ?? 'custom';
            } else {
                $priceId = $this->stripe->resolvePriceIdByPlan((string) $plan);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => ['plan' => [$e->getMessage()]],
            ], 422);
        }

        try {
            $subscription = $this->stripe->createSubscription(
                $request->user(),
                $priceId,
            );
        } catch (ApiErrorException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], 502);
        }

        $secrets = $this->stripe->extractSubscriptionClientSecret($subscription);

        return response()->json([
            'success' => true,
            'message' => 'Suscripción creada.',
            'data' => [
                'plan' => $plan,
                'plan_id' => $priceId,
                'price_id' => $priceId,
                'subscription_id' => $subscription->id,
                'client_secret' => $secrets['client_secret'] ?? null,
                'payment_intent_id' => $secrets['payment_intent_id'] ?? null,
                'status' => $subscription->status,
                'trial_days' => $this->stripe->trialDays(),
                'trial_end' => $subscription->trial_end
                    ? date('c', $subscription->trial_end)
                    : null,
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Suscripciones locales del usuario.
     */
    public function index(Request $request): JsonResponse
    {
        $subscriptions = $request->user()
            ->subscriptions()
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => ['subscriptions' => $subscriptions],
            'errors' => null,
        ]);
    }

    /**
     * Cancelar suscripción (al final del periodo por defecto; ?now=1 inmediata).
     */
    public function destroy(Request $request, string $stripeSubscriptionId): JsonResponse
    {
        $atPeriodEnd = ! $request->boolean('now');

        try {
            $subscription = $this->stripe->cancelSubscription(
                $request->user(),
                $stripeSubscriptionId,
                $atPeriodEnd,
            );
        } catch (ApiErrorException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => $atPeriodEnd
                ? 'Suscripción programada para cancelarse al final del periodo.'
                : 'Suscripción cancelada.',
            'data' => [
                'subscription_id' => $subscription->id,
                'status' => $subscription->status,
                'cancel_at_period_end' => (bool) ($subscription->cancel_at_period_end ?? false),
            ],
            'errors' => null,
        ]);
    }
}
