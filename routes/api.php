<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\GeneratedPostController;
use App\Http\Controllers\RawContentController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CampaignBlueprintController;


Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});


Route::middleware('auth:sanctum')->group(function () {
    Route::delete('auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('blueprints', CampaignBlueprintController::class);

    // Raw Content — nested under blueprints for index/store
    Route::get('blueprints/{blueprint}/raw-contents', [RawContentController::class, 'index']);
    Route::post('blueprints/{blueprint}/raw-contents', [RawContentController::class, 'store']);

    // Raw Content — standalone for show/update/destroy
    Route::get('raw-contents/{rawContent}', [RawContentController::class, 'show']);
    Route::put('raw-contents/{rawContent}', [RawContentController::class, 'update']);
    Route::delete('raw-contents/{rawContent}', [RawContentController::class, 'destroy']);

    // Generated Posts — list, view, update status
    Route::get('posts', [GeneratedPostController::class, 'index']);
    Route::get('posts/{post}', [GeneratedPostController::class, 'show']);
    Route::patch('posts/{post}/status', [GeneratedPostController::class, 'updateStatus']);
});