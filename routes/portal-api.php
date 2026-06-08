<?php

use App\Http\Controllers\Portal\ActivityController;
use App\Http\Controllers\Portal\AuthController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\FeedbackController;
use App\Http\Controllers\Portal\MealPlannerController;
use App\Http\Controllers\Portal\ProfileController;
use App\Http\Controllers\Portal\StudentController;
use App\Http\Controllers\Portal\WalletController;
use Illuminate\Support\Facades\Route;

// Parent auth — public (rate limited)
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:portal-login');
Route::post('/auth/password/email', [AuthController::class, 'forgotPassword'])->middleware('throttle:portal-forgot-password');
Route::post('/auth/password/reset', [AuthController::class, 'resetPassword']);

// Parent portal — authenticated
Route::middleware(['auth:parents', 'ability:parent'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // Profile
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::patch('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/change-password', [ProfileController::class, 'changePassword']);
    Route::post('/profile/photo', [ProfileController::class, 'uploadPhoto']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    // Linked students
    Route::get('/students', [StudentController::class, 'index']);

    // Activity (order history per student)
    Route::get('/students/{student}/activity', [ActivityController::class, 'index']);

    // Wallet
    Route::get('/students/{student}/wallet', [WalletController::class, 'index']);
    Route::patch('/students/{student}/wallet/alert', [WalletController::class, 'setAlert']);

    // Feedback
    Route::get('/feedback', [FeedbackController::class, 'index']);
    Route::post('/feedback', [FeedbackController::class, 'store']);

    // Meal planner
    Route::get('/meal-planner', [MealPlannerController::class, 'show']);
});
