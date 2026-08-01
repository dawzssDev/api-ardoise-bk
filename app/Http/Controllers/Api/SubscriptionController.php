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
     * Productos y precios activos desde Stripe.
     */
    public function plans(): JsonResponse
    {
        try {
            $products = $this->stripe->listProducts(true);
            $prices = $this->stripe->listPrices();
        } catch (ApiErrorException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], 502);
        }

        $pricesByProduct = [];
        foreach ($prices as $price) {
            $productId = is_string($price->product) ? $price->product : $price->product->id;
            $pricesByProduct[$productId][] = [
                'id' => $price->id,
                'currency' => $price->currency,
                'unit_amount' => $price->unit_amount,
                'interval' => $price->recurring->interval ?? null,
                'interval_count' => $price->recurring->interval_count ?? null,
            ];
        }

        $plans = collect($products)->map(function ($product) use ($pricesByProduct) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'prices' => $pricesByProduct[$product->id] ?? [],
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'plans' => $plans,
                'configured_plans' => $this->stripe->configuredPlans(),
                'trial_days' => $this->stripe->trialDays(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Crear suscripción por plan (prueba|mensual|anual) y devolver client_secret.
     */
    public function store(CreateSubscriptionRequest $request): JsonResponse
    {
        $plan = $request->validated('plan');

        try {
            $priceId = $this->stripe->resolvePriceIdByPlan($plan);
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

        $invoice = $subscription->latest_invoice;
        $paymentIntent = is_object($invoice) ? ($invoice->payment_intent ?? null) : null;
        $clientSecret = is_object($paymentIntent) ? $paymentIntent->client_secret : null;

        return response()->json([
            'success' => true,
            'message' => 'Suscripción creada.',
            'data' => [
                'plan' => $plan,
                'price_id' => $priceId,
                'subscription_id' => $subscription->id,
                'client_secret' => $clientSecret,
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
