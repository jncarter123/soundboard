<?php

use App\Http\Controllers\Api\ReverbAppController;
use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class);

// Authenticated with Sanctum personal access tokens (created on the Tokens
// page). Access follows the token owner's permissions, same as the dashboard.
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/apps', [ReverbAppController::class, 'index'])->middleware('can:apps.read');
    Route::post('/apps', [ReverbAppController::class, 'store'])->middleware('can:apps.create');
    Route::get('/apps/{app:app_id}', [ReverbAppController::class, 'show'])->middleware('can:apps.read');
    Route::patch('/apps/{app:app_id}', [ReverbAppController::class, 'update'])->middleware('can:apps.update');
    Route::delete('/apps/{app:app_id}', [ReverbAppController::class, 'destroy'])->middleware('can:apps.delete');

    Route::get('/apps/{app:app_id}/credentials', [ReverbAppController::class, 'credentials'])->middleware('can:apps.update');
    Route::post('/apps/{app:app_id}/credentials', [ReverbAppController::class, 'regenerateCredentials'])->middleware('can:apps.update');
});
