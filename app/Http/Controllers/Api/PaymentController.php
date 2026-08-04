<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\CreatePaymentIntentRequest;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly StripeService $stripe,
    ) {}

    /**
     * Crear PaymentIntent (pago único) o suscripción (plan_id) y devolver client_secret.
     *
     * Compatibilidad con el front:
     * - { amount } → PaymentIntent
     * - { plan_id: "price_..." } → Suscripción Stripe + client_secret
     */
    public function createIntent(CreatePaymentIntentRequest $request): JsonResponse
    {
        $planId = $request->validated('plan_id');

        if (is_string($planId) && $planId !== '') {
            return $this->createSubscriptionFromPlanId($request, $planId);
        }

        try {
            $intent = $this->stripe->createPaymentIntent(
                $request->user(),
                (int) $request->validated('amount'),
                $request->validated('currency'),
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
            'message' => 'PaymentIntent creado.',
            'data' => [
                'client_secret' => $intent->client_secret,
                'payment_intent_id' => $intent->id,
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Flujo del front de suscripción: POST /payments/intent { plan_id }.
     */
    private function createSubscriptionFromPlanId(Request $request, string $planId): JsonResponse
    {
        if (! $this->stripe->isConfiguredPriceId($planId)) {
            return response()->json([
                'success' => false,
                'message' => 'El plan_id no está configurado para ARDOISE.',
                'data' => null,
                'errors' => ['plan_id' => ['El plan_id no está permitido.']],
            ], 422);
        }

        try {
            $subscription = $this->stripe->createSubscription(
                $request->user(),
                $planId,
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

        if ($secrets === null) {
            return response()->json([
                'success' => false,
                'message' => 'La suscripción se creó pero Stripe no devolvió client_secret. Revisa el trial o el price en Stripe.',
                'data' => [
                    'subscription_id' => $subscription->id,
                    'status' => $subscription->status,
                    'client_secret' => null,
                    'payment_intent_id' => null,
                ],
                'errors' => null,
            ], 502);
        }

        $trialDays = $this->stripe->trialDays();
        $intentType = $secrets['intent_type']
            ?? $this->stripe->detectIntentType($secrets['client_secret']);

        return response()->json([
            'success' => true,
            'message' => 'Suscripción creada.',
            'data' => [
                'client_secret' => $secrets['client_secret'],
                'payment_intent_id' => $secrets['payment_intent_id'],
                'subscription_id' => $secrets['subscription_id'],
                'plan_id' => $planId,
                'trial_days' => $trialDays,
                'intent_type' => $intentType,
                'charge_today' => $trialDays <= 0,
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Historial local de pagos del usuario.
     */
    public function index(Request $request): JsonResponse
    {
        $payments = $request->user()
            ->payments()
            ->latest()
            ->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => $payments,
            'errors' => null,
        ]);
    }
}
