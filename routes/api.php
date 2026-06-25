<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CampaignBlueprintController;


Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});


Route::middleware('auth:sanctum')->group(function(){
    Route::delete('auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('blueprints', CampaignBlueprintController::class);
})
?>