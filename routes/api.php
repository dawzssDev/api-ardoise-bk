<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoriaInsumoController;
use App\Http\Controllers\Api\CategoriaProductoController;
use App\Http\Controllers\Api\EmpleadoController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\InsumoController;
use App\Http\Controllers\Api\NegocioController;
use App\Http\Controllers\Api\OrdenController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StockInsumoController;
use App\Http\Controllers\Api\StripeWebhookController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SucursalController;
use App\Http\Controllers\Api\TurnoCajaController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// Los límites de throttle se ajustan en RateLimitServiceProvider, no aquí.

// Endpoint público de salud (prefijo /api ya aplicado por el framework)
Route::get('/health', HealthController::class);

// Registro diferido: cuenta solo se crea tras confirmar pago
Route::post('/auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:register');
Route::get('/auth/register/plans', [AuthController::class, 'registerPlans'])
    ->middleware('throttle:register');
Route::post('/auth/register/checkout', [AuthController::class, 'registerCheckout'])
    ->middleware('throttle:register');
Route::post('/auth/register/complete', [AuthController::class, 'registerComplete'])
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

    // Perfil / facturación: solo usuario maestro
    Route::middleware('master')->group(function () {
        Route::get('/user', [UserController::class, 'show']);
        Route::put('/user', [UserController::class, 'update']);

        Route::put('/negocio', [NegocioController::class, 'update']);

        Route::post('/payments/intent', [PaymentController::class, 'createIntent']);
        Route::get('/payments', [PaymentController::class, 'index']);

        Route::get('/subscriptions/plans', [SubscriptionController::class, 'plans']);
        Route::post('/subscriptions', [SubscriptionController::class, 'store']);
        Route::get('/subscriptions', [SubscriptionController::class, 'index']);
        Route::delete('/subscriptions/{stripeSubscriptionId}', [SubscriptionController::class, 'destroy']);
    });

    // Negocio: lectura permitida a maestro y staff
    Route::get('/negocio', [NegocioController::class, 'show']);

    // Sucursales / bodegas del negocio (throttle:api = máx. 60 req/min)
    Route::get('/sucursales', [SucursalController::class, 'index']);
    Route::post('/sucursales', [SucursalController::class, 'store']);
    Route::get('/sucursales/{id}', [SucursalController::class, 'show'])->whereNumber('id');
    Route::put('/sucursales/{id}', [SucursalController::class, 'update'])->whereNumber('id');
    Route::put('/sucursales/{id}/activa', [SucursalController::class, 'setActive'])->whereNumber('id');

    // Categorías de insumos del negocio (throttle:api = máx. 60 req/min)
    Route::get('/categoria-insumos', [CategoriaInsumoController::class, 'index']);
    Route::post('/categoria-insumos', [CategoriaInsumoController::class, 'store']);
    Route::get('/categoria-insumos/{id}', [CategoriaInsumoController::class, 'show'])->whereNumber('id');
    Route::put('/categoria-insumos/{id}', [CategoriaInsumoController::class, 'update'])->whereNumber('id');
    Route::delete('/categoria-insumos/{id}', [CategoriaInsumoController::class, 'destroy'])->whereNumber('id');

    // Categorías de productos del negocio (throttle:api = máx. 60 req/min)
    Route::get('/categoria-productos', [CategoriaProductoController::class, 'index']);
    Route::post('/categoria-productos', [CategoriaProductoController::class, 'store']);
    Route::get('/categoria-productos/{id}', [CategoriaProductoController::class, 'show'])->whereNumber('id');
    Route::put('/categoria-productos/{id}', [CategoriaProductoController::class, 'update'])->whereNumber('id');
    Route::delete('/categoria-productos/{id}', [CategoriaProductoController::class, 'destroy'])->whereNumber('id');

    // Insumos del negocio (throttle:api = máx. 60 req/min)
    Route::get('/insumos', [InsumoController::class, 'index']);
    Route::post('/insumos', [InsumoController::class, 'store']);
    Route::get('/insumos/{id}', [InsumoController::class, 'show'])->whereNumber('id');
    Route::put('/insumos/{id}', [InsumoController::class, 'update'])->whereNumber('id');
    Route::put('/insumos/{id}/status', [InsumoController::class, 'setStatus'])->whereNumber('id');

    // Stock de insumos por sucursal/bodega (throttle:api = máx. 60 req/min)
    Route::get('/stock-insumos', [StockInsumoController::class, 'index']);
    Route::put('/stock-insumos', [StockInsumoController::class, 'upsert']);
    Route::put('/stock-insumos/bulk', [StockInsumoController::class, 'bulkUpsert']);
    Route::get('/stock-insumos/{id}', [StockInsumoController::class, 'show'])->whereNumber('id');
    Route::put('/stock-insumos/{id}', [StockInsumoController::class, 'update'])->whereNumber('id');

    // Productos del negocio (throttle:api = máx. 60 req/min)
    Route::get('/productos', [ProductoController::class, 'index']);
    Route::post('/productos', [ProductoController::class, 'store']);
    Route::get('/productos/{id}', [ProductoController::class, 'show'])->whereNumber('id');
    // POST también para poder enviar imagen (multipart); PUT sirve sin archivo
    Route::post('/productos/{id}', [ProductoController::class, 'update'])->whereNumber('id');
    Route::put('/productos/{id}', [ProductoController::class, 'update'])->whereNumber('id');
    Route::delete('/productos/{id}', [ProductoController::class, 'destroy'])->whereNumber('id');

    // Roles del negocio (throttle:api = máx. 60 req/min)
    Route::get('/roles', [RoleController::class, 'index']);
    Route::post('/roles', [RoleController::class, 'store']);
    Route::get('/roles/{id}', [RoleController::class, 'show'])->whereNumber('id');
    Route::put('/roles/{id}', [RoleController::class, 'update'])->whereNumber('id');
    Route::put('/roles/{id}/status', [RoleController::class, 'setStatus'])->whereNumber('id');
    Route::delete('/roles/{id}', [RoleController::class, 'destroy'])->whereNumber('id');

    // Empleados / personal del negocio (throttle:api = máx. 60 req/min)
    Route::get('/empleados', [EmpleadoController::class, 'index']);
    Route::post('/empleados', [EmpleadoController::class, 'store']);
    Route::get('/empleados/{id}', [EmpleadoController::class, 'show'])->whereNumber('id');
    // POST también para poder enviar imagen (multipart); PUT sirve sin archivo
    Route::post('/empleados/{id}', [EmpleadoController::class, 'update'])->whereNumber('id');
    Route::put('/empleados/{id}', [EmpleadoController::class, 'update'])->whereNumber('id');
    Route::put('/empleados/{id}/status', [EmpleadoController::class, 'setStatus'])->whereNumber('id');
    Route::delete('/empleados/{id}', [EmpleadoController::class, 'destroy'])->whereNumber('id');

    // Usuarios staff: administración solo maestro
    Route::middleware('master')->group(function () {
        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::get('/staff/{id}', [StaffController::class, 'show'])->whereNumber('id');
        Route::put('/staff/{id}', [StaffController::class, 'update'])->whereNumber('id');
        Route::put('/staff/{id}/status', [StaffController::class, 'setStatus'])->whereNumber('id');
        Route::delete('/staff/{id}', [StaffController::class, 'destroy'])->whereNumber('id');
    });

    // Turnos de caja / corte de caja
    Route::get('/turnos-caja', [TurnoCajaController::class, 'index']);
    Route::get('/turnos-caja/actual', [TurnoCajaController::class, 'actual']);
    Route::post('/turnos-caja/abrir', [TurnoCajaController::class, 'store']);
    Route::get('/turnos-caja/{id}', [TurnoCajaController::class, 'show'])->whereNumber('id');
    Route::get('/turnos-caja/{id}/preview', [TurnoCajaController::class, 'preview'])->whereNumber('id');
    Route::post('/turnos-caja/{id}/cerrar', [TurnoCajaController::class, 'cerrar'])->whereNumber('id');
    Route::get('/turnos-caja/{id}/ventas', [TurnoCajaController::class, 'ventas'])->whereNumber('id');

    // Órdenes POS (header + detalle)
    Route::get('/ordenes', [OrdenController::class, 'index']);
    Route::get('/ordenes/cocina', [OrdenController::class, 'cocina']);
    Route::post('/ordenes', [OrdenController::class, 'store']);
    Route::get('/ordenes/{id}', [OrdenController::class, 'show'])->whereNumber('id');
    Route::put('/ordenes/{id}/status', [OrdenController::class, 'setStatus'])->whereNumber('id');
    Route::put('/ordenes/{id}/detalles/{detalleId}/status', [OrdenController::class, 'setDetalleStatus'])
        ->whereNumber('id')
        ->whereNumber('detalleId');
});
