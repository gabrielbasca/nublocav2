<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationMessageController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\MessageTemplateController;
use App\Http\Controllers\Api\PublicVehicleAlertController;
use App\Http\Controllers\Api\VehicleAlertController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Anonymous, privacy-safe vehicle alert endpoints. The token is the vehicle's
// public QR token; these never expose owner identity or contact details.
Route::get('/public/vehicles/{token}', [PublicVehicleAlertController::class, 'show'])
    ->where('token', '[a-f0-9]{64}')
    ->name('api.public.vehicles.show');
Route::post('/public/vehicles/{token}/alerts', [PublicVehicleAlertController::class, 'store'])
    ->where('token', '[a-f0-9]{64}')
    ->middleware('throttle:public-vehicle-alert')
    ->name('api.public.vehicles.alerts.store');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    Route::get('/message-templates', [MessageTemplateController::class, 'index']);
    Route::post('/vehicles/{vehicle}/rotate-public-token', [VehicleController::class, 'rotatePublicToken']);
    Route::apiResource('vehicles', VehicleController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::get('/vehicles/{vehicle}/alerts', [VehicleAlertController::class, 'index']);
    Route::post('/vehicles/{vehicle}/alerts/{alert}/read', [VehicleAlertController::class, 'markRead']);

    Route::post('/contact', [ConversationController::class, 'contact'])->middleware('throttle:contact');
    Route::get('/conversations', [ConversationController::class, 'index']);
    Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
    Route::post('/conversations/{conversation}/messages', [ConversationMessageController::class, 'store'])
        ->middleware('throttle:conversation-message');

    Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
    Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy']);
});
