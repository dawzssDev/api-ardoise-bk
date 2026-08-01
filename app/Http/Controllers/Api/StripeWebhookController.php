<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\HandleStripeWebhookSideEffect;
use App\Models\Payment;
use App\Models\StripeEvent;
use App\Services\StripeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeService $stripe,
    ) {}

    /**
     * Webhook público de Stripe: verifica firma y procesa eventos.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = (string) $request->header('Stripe-Signature', '');

        try {
            $event = $this->stripe->constructWebhookEvent($payload, $sigHeader);
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Stripe signature.',
                'data' => null,
                'errors' => null,
            ], 400);
        }

        // Idempotencia: evento ya procesado → 200 sin repetir
        if (StripeEvent::query()->where('stripe_event_id', $event->id)->exists()) {
            return response()->json([
                'success' => true,
                'message' => 'Event already processed.',
                'data' => null,
                'errors' => null,
            ]);
        }

        match ($event->type) {
            'payment_intent.succeeded' => $this->handlePaymentIntentStatus($event->data->object->id, 'succeeded'),
            'payment_intent.payment_failed' => $this->handlePaymentIntentStatus($event->data->object->id, 'payment_failed'),
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->handleSubscriptionObject($event->data->object),
            'invoice.paid',
            'invoice.payment_failed' => $this->handleInvoiceSubscription($event->data->object),
            default => null,
        };

        StripeEvent::query()->create([
            'stripe_event_id' => $event->id,
            'type' => $event->type,
            'processed_at' => now(),
        ]);

        // Side effects pesados a la cola (emails, etc.)
        if (in_array($event->type, ['payment_intent.succeeded', 'invoice.paid'], true)) {
            HandleStripeWebhookSideEffect::dispatch($event->type, [
                'event_id' => $event->id,
                'object_id' => $event->data->object->id ?? null,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Webhook handled.',
            'data' => null,
            'errors' => null,
        ]);
    }

    private function handlePaymentIntentStatus(string $paymentIntentId, string $status): void
    {
        Payment::query()
            ->where('stripe_payment_intent_id', $paymentIntentId)
            ->update(['status' => $status]);
    }

    private function handleSubscriptionObject(object $subscription): void
    {
        try {
            // Rehidrata como tipo Stripe\Subscription si viene como StripeObject
            if (! $subscription instanceof \Stripe\Subscription) {
                $subscription = $this->stripe->retrieveSubscription($subscription->id);
            }

            $this->stripe->syncSubscriptionFromStripe($subscription);
        } catch (ApiErrorException $e) {
            Log::error('Stripe webhook subscription sync failed', [
                'message' => $e->getMessage(),
                'subscription_id' => $subscription->id ?? null,
            ]);
        }
    }

    private function handleInvoiceSubscription(object $invoice): void
    {
        $subscriptionId = is_string($invoice->subscription ?? null)
            ? $invoice->subscription
            : ($invoice->subscription->id ?? null);

        if (! $subscriptionId) {
            return;
        }

        try {
            $subscription = $this->stripe->retrieveSubscription($subscriptionId);
            $this->stripe->syncSubscriptionFromStripe($subscription);
        } catch (ApiErrorException $e) {
            Log::error('Stripe webhook invoice subscription sync failed', [
                'message' => $e->getMessage(),
                'subscription_id' => $subscriptionId,
            ]);
        }
    }
}
