<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FlightController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Flights endpoint
Route::get('/flights', [FlightController::class, 'index']);

// Health check endpoint
Route::get('/health', [FlightController::class, 'health']);
