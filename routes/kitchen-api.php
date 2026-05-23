<?php

use App\Http\Controllers\Kitchen\AuthController;
use Illuminate\Support\Facades\Route;

// Staff auth — public
Route::post('/auth/login', [AuthController::class, 'login']);

// Staff auth — authenticated
Route::middleware(['auth:sanctum', 'ability:staff'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);
});
