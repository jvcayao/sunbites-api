<?php

use App\Http\Controllers\Portal\ActivityController;
use App\Http\Controllers\Portal\AuthController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\FeedbackController;
use App\Http\Controllers\Portal\MealPlannerController;
use App\Http\Controllers\Portal\NotificationController;
use App\Http\Controllers\Portal\PreRegistrationCheckController;
use App\Http\Controllers\Portal\PreRegistrationController;
use App\Http\Controllers\Portal\ProfileController;
use App\Http\Controllers\Portal\SpendingSummaryController;
use App\Http\Controllers\Portal\StudentController;
use App\Http\Controllers\Portal\StudentPaymentHistoryController;
use App\Http\Controllers\Portal\StudentPhotoController;
use App\Http\Controllers\Portal\WalletController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

// Pre-registration — public (rate limited)
Route::middleware(['throttle:10,1'])->group(function () {
    Route::post('/pre-registrations/check', [PreRegistrationCheckController::class, 'check']);
});

// Pre-registration submit — public (rate limited)
Route::middleware(['throttle:5,10'])->group(function () {
    Route::post('/pre-registrations', [PreRegistrationController::class, 'store']);
});

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

    // Spending summary (charts + aggregates)
    Route::get('/students/{student}/spending-summary', [SpendingSummaryController::class, 'show']);

    // Wallet
    Route::get('/students/{student}/wallet', [WalletController::class, 'index']);
    Route::patch('/students/{student}/wallet/alert', [WalletController::class, 'setAlert']);

    // Student photo
    Route::get('/students/{student}/photo', [StudentPhotoController::class, 'show']);
    Route::post('/students/{student}/photo', [StudentPhotoController::class, 'store']);

    // Feedback
    Route::get('/feedback', [FeedbackController::class, 'index']);
    Route::post('/feedback', [FeedbackController::class, 'store']);

    // Meal planner
    Route::get('/meal-planner', [MealPlannerController::class, 'show']);

    // Broadcast auth for private channels (Reverb)
    Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate']);

    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead']);
    Route::delete('/notifications', [NotificationController::class, 'clearAll']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);

    // Payment history per student
    Route::get('/students/{student}/payment-history', [StudentPaymentHistoryController::class, 'index']);
});
