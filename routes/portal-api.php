<?php

use App\Http\Controllers\Kitchen\MealPlannerController;
use App\Http\Controllers\Portal\AuthController;
use Illuminate\Support\Facades\Route;

// Parent auth — public
Route::post('/auth/login', [AuthController::class, 'login']);

// Parent auth — authenticated
Route::middleware(['auth:sanctum', 'ability:parent'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/meal-planner', [MealPlannerController::class, 'show']);
});
