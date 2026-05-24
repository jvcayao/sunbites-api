<?php

use App\Http\Controllers\Kitchen\AuthController;
use App\Http\Controllers\Kitchen\BranchController;
use App\Http\Controllers\Kitchen\InventoryController;
use App\Http\Controllers\Kitchen\MealPlannerController;
use App\Http\Controllers\Kitchen\PosMenuItemController;
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

    // POS Menu Items — admin, manager only
    Route::middleware('role:admin|manager')->group(function () {
        Route::get('/pos/menu-items', [PosMenuItemController::class, 'index']);
        Route::post('/pos/menu-items', [PosMenuItemController::class, 'store']);
        Route::post('/pos/menu-items/{item}/toggle', [PosMenuItemController::class, 'toggleAvailability']);
        Route::delete('/pos/menu-items/{item}', [PosMenuItemController::class, 'destroy']);
    });

    // Meal Planner — view for all staff; edit/reset for admin, manager only
    Route::get('/references/meal-planner', [MealPlannerController::class, 'show']);
    Route::middleware('role:admin|manager')->group(function () {
        Route::patch('/references/meal-planner', [MealPlannerController::class, 'update']);
        Route::post('/references/meal-planner/reset', [MealPlannerController::class, 'reset']);
    });

    // Inventory — admin, manager, supervisor
    // /pos/inventory* — POS screen (read + adjust); /references/inventory* — management (full CRUD)
    Route::middleware('role:admin|manager|supervisor')->group(function () {
        Route::get('/pos/inventory', [InventoryController::class, 'index']);
        Route::post('/pos/inventory/{item}/adjust', [InventoryController::class, 'adjust']);
        Route::get('/references/inventory', [InventoryController::class, 'index']);
        Route::post('/references/inventory', [InventoryController::class, 'store']);
        Route::put('/references/inventory/{item}', [InventoryController::class, 'update']);
        Route::delete('/references/inventory/{item}', [InventoryController::class, 'destroy']);
        Route::get('/references/inventory/{item}/logs', [InventoryController::class, 'logs']);
    });

    // Branch management + User management — admin only
    Route::middleware('role:admin')->group(function () {
        Route::get('/branches', [BranchController::class, 'index']);
        Route::put('/branches/{branch}', [BranchController::class, 'update']);
        Route::post('/branches/{branch}/toggle', [BranchController::class, 'toggleActive']);

        Route::apiResource('users', UserManagementController::class)->except('destroy');
        Route::post('/users/{user}/deactivate', [UserManagementController::class, 'deactivate']);
        Route::post('/users/{user}/reactivate', [UserManagementController::class, 'reactivate'])->withTrashed();
        Route::post('/users/{user}/reset-password', [UserManagementController::class, 'sendResetEmail']);
        Route::post('/users/{user}/photo', [UserManagementController::class, 'uploadPhoto']);
        Route::post('/users/{user}/branches', [UserManagementController::class, 'assignBranch']);
        Route::delete('/users/{user}/branches/{branch}', [UserManagementController::class, 'detachBranch']);
    });
});
