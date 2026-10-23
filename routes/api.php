<?php

use App\Http\Controllers\MockInsurerController;
use App\Http\Controllers\PolicyRequestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PolicyFlow API Routes
|--------------------------------------------------------------------------
*/

// Policy Request endpoints
Route::prefix('policy-requests')->group(function () {
    Route::get('/', [PolicyRequestController::class, 'index']);
    Route::post('/', [PolicyRequestController::class, 'store']);
    Route::get('/{id}', [PolicyRequestController::class, 'show']);
    Route::post('/{id}/submit', [PolicyRequestController::class, 'submit']);
});

// Mock Insurer endpoints
Route::prefix('mock-insurer')->group(function () {
    Route::post('/callback', [MockInsurerController::class, 'callback']);
    Route::get('/status/{uuid}', [MockInsurerController::class, 'status']);
});
