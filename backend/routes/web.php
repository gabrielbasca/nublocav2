<?php

use App\Http\Controllers\PublicVehicleController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/v/{publicToken}', [PublicVehicleController::class, 'show'])
    ->where('publicToken', '[a-f0-9]{64}')
    ->name('vehicles.public.show');
Route::post('/v/{publicToken}', [PublicVehicleController::class, 'contact'])
    ->where('publicToken', '[a-f0-9]{64}')
    ->middleware('throttle:public-vehicle-contact')
    ->name('vehicles.public.contact');
