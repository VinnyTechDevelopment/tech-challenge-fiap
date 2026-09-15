<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Presentation\Http\Controllers\ServiceOrderController;
use App\Presentation\Http\Controllers\ServiceOrderServiceController;
use App\Presentation\Http\Controllers\ItemController;
use App\Presentation\Http\Controllers\NotificationController;
use App\Presentation\Http\Controllers\StockController;
use App\Presentation\Http\Controllers\ServiceController;
use App\Presentation\Http\Controllers\CustomerController;
use App\Presentation\Http\Controllers\VehicleController;

use App\Presentation\Http\Controllers\ServiceOrderApprovalController;

Route::get('/service-order/approve/{token}', [ServiceOrderApprovalController::class, 'approve']);
Route::get('/service-order/reject/{token}', [ServiceOrderApprovalController::class, 'reject']);
Route::post('/service-order/approval/{token}', [ServiceOrderApprovalController::class, 'handle']);

Route::group([
    'middleware' => 'auth.customer',
    'prefix' => 'customer'
], function () {
    Route::get('/me', [CustomerController::class, 'me']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'customer'
], function () {
    Route::get('/', [CustomerController::class, 'list']);
    Route::post('/', [CustomerController::class, 'store']);
    Route::get('/{id}', [CustomerController::class, 'show']);
    Route::put('/{id}', [CustomerController::class, 'update']);
    Route::delete('/{id}', [CustomerController::class, 'destroy']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'auth'
], function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login'])->name('login');
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:api');
    Route::post('refresh', [AuthController::class, 'refresh'])->middleware('auth:api');
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:api');
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'vehicle'
], function () {
    Route::get('/', [VehicleController::class, 'list']);
    Route::post('/', [VehicleController::class, 'store']);
    Route::get('/{id}', [VehicleController::class, 'show']);
    Route::put('/{id}', [VehicleController::class, 'update']);
    Route::delete('/{id}', [VehicleController::class, 'destroy']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'service-order'
], function () {
    Route::get('/', [ServiceOrderController::class, 'list']);
    Route::post('/', [ServiceOrderController::class, 'store']);
    Route::get('/{id}', [ServiceOrderController::class, 'show']);
    Route::put('/{id}', [ServiceOrderController::class, 'update']);
    Route::patch('/{id}/status', [ServiceOrderController::class, 'updateStatus']);
    Route::delete('/{id}/services/{serviceId}', [ServiceOrderController::class, 'removeService']);
    Route::delete('/{id}/items/{itemId}', [ServiceOrderController::class, 'removeItem']);
    Route::delete('/{id}', [ServiceOrderController::class, 'destroy']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'service-order-service'
], function () {
    Route::get('/metrics/average-execution-time', [ServiceOrderServiceController::class, 'averageExecutionTime']);
    Route::patch('/{id}/start', [ServiceOrderServiceController::class, 'start']);
    Route::patch('/{id}/finish', [ServiceOrderServiceController::class, 'finish']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'item'
], function () {
    Route::get('/', [ItemController::class, 'list']);
    Route::post('/', [ItemController::class, 'store']);
    Route::get('/{id}', [ItemController::class, 'show']);
    Route::put('/{id}', [ItemController::class, 'update']);
    Route::delete('/{id}', [ItemController::class, 'destroy']);

    Route::post('/{id}/stock/entry', [StockController::class, 'entry']);
    Route::post('/{id}/stock/withdrawal', [StockController::class, 'withdrawal']);
    Route::get('/{id}/stock/movements', [StockController::class, 'movements']);
});
Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'service'
], function () {
    Route::get('/', [ServiceController::class, 'list']);
    Route::post('/', [ServiceController::class, 'store']);
    Route::get('/{id}', [ServiceController::class, 'show']);
    Route::put('/{id}', [ServiceController::class, 'update']);
    Route::delete('/{id}', [ServiceController::class, 'destroy']);
});

Route::group([
    'middleware' => 'auth:api',
    'prefix' => 'notification'
], function () {
    Route::get('/', [NotificationController::class, 'list']);
    Route::get('/{id}', [NotificationController::class, 'show']);
});