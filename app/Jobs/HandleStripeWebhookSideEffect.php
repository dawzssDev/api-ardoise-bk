<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Side effects pesados de webhooks Stripe (emails, notificaciones, etc.).
 */
class HandleStripeWebhookSideEffect implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventType,
        public array $payload = [],
    ) {}

    public function handle(): void
    {
        Log::info('Stripe webhook side effect', [
            'type' => $this->eventType,
            'payload' => $this->payload,
        ]);
    }
}
