<?php

use App\Http\Controllers\AuthController;
use App\Presentation\Http\Controllers\CustomerController;
use App\Presentation\Http\Controllers\InternalCustomerController;
use App\Presentation\Http\Controllers\NotificationController;
use App\Presentation\Http\Controllers\ServiceOrderController;
use App\Presentation\Http\Controllers\VehicleController;
use Illuminate\Support\Facades\Route;

/*
 * os-service — Ordens de Serviço (orquestrador da saga), clientes, veículos,
 * autenticação de funcionários e notificações.
 *
 * Catálogo/estoque/execução: execution-service (/api/catalog, /api/execution).
 * Orçamento/pagamento: billing-service (/api/billing).
 */

// Uso interno (Lambda lambda-auth-cpf), protegido por X-Internal-Token.
Route::middleware('auth.internal')->prefix('internal')->group(function () {
    Route::get('/customers/by-document/{document}', [InternalCustomerController::class, 'byDocument']);
});

Route::group([
    'middleware' => 'auth.customer',
    'prefix' => 'customer',
], function () {
    Route::get('/me', [CustomerController::class, 'me']);
    Route::get('/me/service-orders', [CustomerController::class, 'myServiceOrders']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'customer',
], function () {
    Route::get('/', [CustomerController::class, 'list']);
    Route::post('/', [CustomerController::class, 'store']);
    Route::get('/{id}', [CustomerController::class, 'show']);
    Route::put('/{id}', [CustomerController::class, 'update']);
    Route::delete('/{id}', [CustomerController::class, 'destroy']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'auth',
], function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:api');
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'vehicle',
], function () {
    Route::get('/', [VehicleController::class, 'list']);
    Route::post('/', [VehicleController::class, 'store']);
    Route::get('/{id}', [VehicleController::class, 'show']);
    Route::put('/{id}', [VehicleController::class, 'update']);
    Route::delete('/{id}', [VehicleController::class, 'destroy']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'service-order',
], function () {
    Route::get('/', [ServiceOrderController::class, 'list']);
    Route::post('/', [ServiceOrderController::class, 'store']);
    Route::get('/{id}', [ServiceOrderController::class, 'show']);
    Route::get('/{id}/history', [ServiceOrderController::class, 'history']);
    Route::patch('/{id}/status', [ServiceOrderController::class, 'updateStatus']);
    Route::post('/{id}/cancel', [ServiceOrderController::class, 'cancel']);
    Route::delete('/{id}', [ServiceOrderController::class, 'destroy']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'notification',
], function () {
    Route::get('/', [NotificationController::class, 'list']);
    Route::get('/{id}', [NotificationController::class, 'show']);
});
