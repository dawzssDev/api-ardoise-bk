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
     * Crear PaymentIntent y devolver client_secret.
     */
    public function createIntent(CreatePaymentIntentRequest $request): JsonResponse
    {
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
