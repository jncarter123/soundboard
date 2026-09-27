<?php

use App\Http\Controllers\Api\ReverbAppController;
use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class);

// Authenticated with Sanctum personal access tokens (created on the Tokens
// page). Access follows the token owner's permissions and team roles, same
// as the dashboard; see ReverbAppPolicy.
Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/apps', [ReverbAppController::class, 'index'])->middleware('can:viewAny,App\Models\ReverbApp');
    // Authorized in the controller, once the requested team is known.
    Route::post('/apps', [ReverbAppController::class, 'store']);
    Route::get('/apps/{app:app_id}', [ReverbAppController::class, 'show'])->middleware('can:view,app');
    Route::patch('/apps/{app:app_id}', [ReverbAppController::class, 'update'])->middleware('can:update,app');
    Route::delete('/apps/{app:app_id}', [ReverbAppController::class, 'destroy'])->middleware('can:delete,app');

    Route::get('/apps/{app:app_id}/credentials', [ReverbAppController::class, 'credentials'])->middleware('can:update,app');
    Route::post('/apps/{app:app_id}/credentials', [ReverbAppController::class, 'regenerateCredentials'])->middleware('can:update,app');
});
