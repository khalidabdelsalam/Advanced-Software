<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ObservabilityController;
use App\Http\Controllers\MetricsController;

Route::middleware(['telemetry'])->group(function () {
    Route::get('/normal', [ObservabilityController::class, 'normal'])->name('api.normal');
    Route::get('/slow', [ObservabilityController::class, 'slow'])->name('api.slow');
    Route::get('/error', [ObservabilityController::class, 'error'])->name('api.error');
    Route::get('/random', [ObservabilityController::class, 'random'])->name('api.random');
    Route::get('/db', [ObservabilityController::class, 'db'])->name('api.db');
    Route::post('/validate', [ObservabilityController::class, 'validateInput'])->name('api.validate');
});

// Metrics endpoint should have no telemetry logging
Route::get('/metrics', [MetricsController::class, 'metrics'])->withoutMiddleware(['telemetry'])->name('metrics');
