<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterCheckoutRequest;
use App\Http\Requests\Auth\RegisterCompleteRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\NegocioResource;
use App\Http\Resources\StaffResource;
use App\Http\Resources\TurnoCajaResource;
use App\Http\Resources\UserResource;
use App\Models\PendingRegistration;
use App\Models\Staff;
use App\Models\User;
use App\Services\AuthService;
use App\Services\RegisterService;
use App\Services\StripeService;
use App\Services\TurnoCajaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthController extends Controller
{
    public function __construct(
        private readonly RegisterService $registerService,
        private readonly AuthService $authService,
        private readonly StripeService $stripe,
        private readonly TurnoCajaService $turnosCaja,
    ) {}

    /**
     * Paso 1: guardar datos de cuenta como registro pendiente (sin crear User).
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $pending = $this->registerService->createPending($request->validated());
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e->getStatusCode());
        }

        return response()->json([
            'success' => true,
            'message' => 'Datos guardados. Continúa con el pago para activar tu cuenta.',
            'data' => [
                'registration_token' => $pending->token,
                'expires_at' => $pending->expires_at?->toIso8601String(),
                'email' => $pending->email,
                // Sin user/token: la cuenta se crea tras confirmar el pago.
                'user' => null,
                'token' => null,
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Planes públicos para el paso de pago del registro.
     */
    public function registerPlans(): JsonResponse
    {
        $configuredPlans = $this->stripe->configuredPlans();
        $enrichedConfigured = [];

        try {
            foreach ($configuredPlans as $configured) {
                $price = $this->stripe->retrievePrice($configured['price_id']);
                $product = $price->product;
                $unitAmount = (int) ($price->unit_amount ?? 0);

                $enrichedConfigured[] = [
                    'plan' => $configured['plan'],
                    'price_id' => $configured['price_id'],
                    'currency' => $price->currency,
                    'unit_amount' => $unitAmount,
                    'amount' => $unitAmount / 100,
                    'interval' => $price->recurring?->interval,
                    'interval_count' => $price->recurring?->interval_count,
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

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'configured_plans' => $enrichedConfigured,
                'trial_days' => $this->stripe->trialDays(),
            ],
            'errors' => null,
        ]);
    }

    /**
     * Paso 2: iniciar suscripción Stripe del registro pendiente.
     */
    public function registerCheckout(RegisterCheckoutRequest $request): JsonResponse
    {
        try {
            $pending = $this->registerService->findOpenByToken(
                $request->validated('registration_token'),
            );

            $priceId = $request->validated('plan_id');
            if (! is_string($priceId) || $priceId === '') {
                $priceId = $this->stripe->resolvePriceIdByPlan((string) $request->validated('plan'));
            }

            $result = $this->registerService->startCheckout($pending, $priceId);
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e->getStatusCode());
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], 422);
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
            'message' => 'Suscripción creada. Confirma el pago para activar tu cuenta.',
            'data' => [
                'registration_token' => $result['pending']->token,
                'client_secret' => $result['client_secret'],
                'payment_intent_id' => $result['payment_intent_id'],
                'subscription_id' => $result['subscription_id'],
                'plan_id' => $result['plan_id'],
                'trial_days' => $result['trial_days'],
                // Front: payment_intent → stripe.confirmPayment | setup_intent → stripe.confirmSetup
                'intent_type' => $result['intent_type'],
                'charge_today' => $result['charge_today'],
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Paso 3: tras pago confirmado, crear User + Negocio y emitir token.
     */
    public function registerComplete(RegisterCompleteRequest $request): JsonResponse
    {
        try {
            $pending = $this->registerService->findOpenByToken(
                $request->validated('registration_token'),
            );
        } catch (HttpException $e) {
            // Si ya se completó, permitir reemitir token
            if ($e->getStatusCode() === 422 && str_contains($e->getMessage(), 'ya fue completado')) {
                return $this->completeAlreadyFinished($request->validated('registration_token'));
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e->getStatusCode());
        }

        try {
            $result = $this->registerService->complete($pending);
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e->getStatusCode());
        } catch (ApiErrorException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], 502);
        }

        $user = $result['user']->load('negocio');
        $token = $this->authService->issueToken($user);

        return response()->json([
            'success' => true,
            'message' => 'Pago confirmado. Cuenta creada correctamente.',
            'data' => [
                'type' => 'user',
                'user' => (new UserResource($user))->resolve(),
                'negocio' => (new NegocioResource($result['negocio']))->resolve(),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
            'errors' => null,
        ], 201);
    }

    /**
     * Iniciar sesión (users maestro o staff) y emitir token Bearer.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->attempt(
                $request->loginIdentifier(),
                $request->validated('password'),
            );
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null,
                'errors' => null,
            ], $e->getStatusCode());
        }

        /** @var User|Staff $actor */
        $actor = $result['actor'];
        $token = $this->authService->issueToken($actor);

        if ($result['type'] === 'staff') {
            /** @var Staff $actor */
            $actor->load([
                'negocio',
                'sucursal:id,negocio_id,type,name',
                'role:id,negocio_id,name,permissions,status',
                'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname,employee_number,status',
            ]);

            $caja = $this->cajaPayload($actor);

            return response()->json([
                'success' => true,
                'message' => 'Inicio de sesión correcto.',
                'data' => [
                    'type' => 'staff',
                    'user' => null,
                    'staff' => (new StaffResource($actor))->resolve(),
                    'negocio' => $actor->negocio
                        ? (new NegocioResource($actor->negocio))->resolve()
                        : null,
                    'caja' => $caja,
                    'token' => $token,
                    'token_type' => 'Bearer',
                ],
                'errors' => null,
            ]);
        }

        /** @var User $actor */
        $actor->load('negocio');

        return response()->json([
            'success' => true,
            'message' => 'Inicio de sesión correcto.',
            'data' => [
                'type' => 'user',
                'user' => (new UserResource($actor))->resolve(),
                'staff' => null,
                'caja' => $this->cajaPayload($actor),
                'token' => $token,
                'token_type' => 'Bearer',
            ],
            'errors' => null,
        ]);
    }

    /**
     * Cerrar sesión revocando solo el token actual.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente.',
            'data' => null,
            'errors' => null,
        ]);
    }

    /**
     * Devolver el actor autenticado (maestro o staff).
     */
    public function me(Request $request): JsonResponse
    {
        $actor = $request->user();

        if ($actor instanceof Staff) {
            $actor->load([
                'negocio',
                'sucursal:id,negocio_id,type,name',
                'role:id,negocio_id,name,permissions,status',
                'empleado:id,negocio_id,first_name,paternal_surname,maternal_surname,employee_number,status',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'ok',
                'data' => [
                    'type' => 'staff',
                    'user' => null,
                    'staff' => (new StaffResource($actor))->resolve(),
                    'negocio' => $actor->negocio
                        ? (new NegocioResource($actor->negocio))->resolve()
                        : null,
                    'caja' => $this->cajaPayload($actor),
                ],
                'errors' => null,
            ]);
        }

        $actor->load('negocio');

        return response()->json([
            'success' => true,
            'message' => 'ok',
            'data' => [
                'type' => 'user',
                'user' => (new UserResource($actor))->resolve(),
                'staff' => null,
                'caja' => $this->cajaPayload($actor),
            ],
            'errors' => null,
        ]);
    }

    /**
     * @return array{caja_abierta: bool, requiere_abrir_caja: bool, turno: array<string, mixed>|null}
     */
    private function cajaPayload(User|Staff $actor): array
    {
        $negocio = $actor->negocio;
        if (! $negocio) {
            return [
                'caja_abierta' => false,
                'requiere_abrir_caja' => true,
                'turno' => null,
            ];
        }

        $sucursalId = $actor instanceof Staff ? (int) $actor->sucursal_id : null;
        $turno = $this->turnosCaja->openTurnoForActor($negocio, $actor, $sucursalId);

        return [
            'caja_abierta' => $turno !== null,
            'requiere_abrir_caja' => $turno === null,
            'turno' => $turno ? (new TurnoCajaResource($turno))->resolve() : null,
        ];
    }

    private function completeAlreadyFinished(string $token): JsonResponse
    {
        $pending = PendingRegistration::query()
            ->where('token', $token)
            ->where('status', PendingRegistration::STATUS_COMPLETED)
            ->first();

        if (! $pending?->user_id) {
            return response()->json([
                'success' => false,
                'message' => 'Este registro ya fue completado. Inicia sesión.',
                'data' => null,
                'errors' => null,
            ], 422);
        }

        $user = User::query()->with('negocio')->findOrFail($pending->user_id);
        $tokenApi = $this->authService->issueToken($user);

        return response()->json([
            'success' => true,
            'message' => 'La cuenta ya estaba activa.',
            'data' => [
                'type' => 'user',
                'user' => (new UserResource($user))->resolve(),
                'negocio' => $user->negocio
                    ? (new NegocioResource($user->negocio))->resolve()
                    : null,
                'token' => $tokenApi,
                'token_type' => 'Bearer',
            ],
            'errors' => null,
        ]);
    }
}
