<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\SubscriptionController;
use Illuminate\Support\Facades\Route;

// Los límites de throttle se ajustan en RateLimitServiceProvider, no aquí.

// Endpoint público de salud (prefijo /api ya aplicado por el framework)
Route::get('/health', HealthController::class);

Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:register');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:auth');

Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])
    ->middleware('throttle:password-reset');

Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:password-reset');

// Webhook Stripe: público, sin auth:sanctum ni throttle:api
Route::post('/stripe/webhook', StripeWebhookController::class)
    ->middleware('throttle:webhooks');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::post('/payments/intent', [PaymentController::class, 'createIntent']);
    Route::get('/payments', [PaymentController::class, 'index']);

    Route::get('/subscriptions/plans', [SubscriptionController::class, 'plans']);
    Route::post('/subscriptions', [SubscriptionController::class, 'store']);
    Route::get('/subscriptions', [SubscriptionController::class, 'index']);
    Route::delete('/subscriptions/{stripeSubscriptionId}', [SubscriptionController::class, 'destroy']);
});
