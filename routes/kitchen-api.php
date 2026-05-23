<?php

use App\Http\Controllers\Kitchen\AuthController;
use App\Http\Controllers\Kitchen\BranchController;
use App\Http\Controllers\Kitchen\UserManagementController;
use Illuminate\Support\Facades\Route;

// Staff auth — public (rate limited)
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/password/email', [AuthController::class, 'sendResetEmail'])->middleware('throttle:password-reset');
Route::post('/auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');

// Staff auth — authenticated
Route::middleware(['auth:sanctum', 'ability:staff'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);
    Route::post('/auth/branch', [AuthController::class, 'setBranch']);

    // Branch list + User management — admin only
    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/branches', [BranchController::class, 'index']);
        Route::apiResource('users', UserManagementController::class)->except('destroy');
        Route::post('/users/{user}/deactivate', [UserManagementController::class, 'deactivate']);
        Route::post('/users/{user}/reactivate', [UserManagementController::class, 'reactivate'])->withTrashed();
        Route::post('/users/{user}/reset-password', [UserManagementController::class, 'sendResetEmail']);
        Route::post('/users/{user}/photo', [UserManagementController::class, 'uploadPhoto']);
        Route::post('/users/{user}/branches', [UserManagementController::class, 'assignBranch']);
        Route::delete('/users/{user}/branches/{branch}', [UserManagementController::class, 'detachBranch']);
    });
});
