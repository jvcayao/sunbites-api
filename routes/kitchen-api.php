<?php

use App\Http\Controllers\Kitchen\AuthController;
use App\Http\Controllers\Kitchen\BranchController;
use App\Http\Controllers\Kitchen\BranchMonthlyAmountController;
use App\Http\Controllers\Kitchen\CheckoutController;
use App\Http\Controllers\Kitchen\CreditController;
use App\Http\Controllers\Kitchen\EnrollmentController;
use App\Http\Controllers\Kitchen\FeedbackController;
use App\Http\Controllers\Kitchen\InlineReloadController;
use App\Http\Controllers\Kitchen\InventoryController;
use App\Http\Controllers\Kitchen\MealPlannerController;
use App\Http\Controllers\Kitchen\ParentController;
use App\Http\Controllers\Kitchen\PaymentController;
use App\Http\Controllers\Kitchen\PosMenuItemController;
use App\Http\Controllers\Kitchen\StudentContactController;
use App\Http\Controllers\Kitchen\StudentController;
use App\Http\Controllers\Kitchen\StudentLookupController;
use App\Http\Controllers\Kitchen\SystemConfigurationController;
use App\Http\Controllers\Kitchen\TransactionController;
use App\Http\Controllers\Kitchen\UserManagementController;
use App\Http\Controllers\Kitchen\WalletController;
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

    // POS Menu Items — GET available to all staff; mutations admin/manager only
    Route::get('/pos/menu-items', [PosMenuItemController::class, 'index']);
    Route::middleware('role:admin|manager')->group(function () {
        Route::post('/pos/menu-items', [PosMenuItemController::class, 'store']);
        Route::put('/pos/menu-items/{item}', [PosMenuItemController::class, 'update']);
        Route::post('/pos/menu-items/{item}/toggle', [PosMenuItemController::class, 'toggleAvailability']);
        Route::delete('/pos/menu-items/{item}', [PosMenuItemController::class, 'destroy']);
    });

    // POS — student lookup, checkout, transactions — all staff
    Route::post('/pos/students/lookup', [StudentLookupController::class, 'lookup']);
    Route::get('/pos/students/{student}', [StudentLookupController::class, 'show']);
    Route::post('/pos/checkout', [CheckoutController::class, 'store']);
    Route::post('/pos/inline-reload', [InlineReloadController::class, 'store']);
    Route::get('/pos/transactions', [TransactionController::class, 'index']);

    // POS — void — admin, manager, supervisor only
    Route::middleware('role:admin|manager|supervisor')->group(function () {
        Route::post('/pos/transactions/{order}/void', [TransactionController::class, 'void']);
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

    // Branch management + System configuration — admin only
    Route::middleware('role:admin')->group(function () {
        Route::get('/branches', [BranchController::class, 'index']);
        Route::put('/branches/{branch}', [BranchController::class, 'update']);
        Route::post('/branches/{branch}/toggle', [BranchController::class, 'toggleActive']);

        Route::get('/system-configurations', [SystemConfigurationController::class, 'index']);
        Route::put('/system-configurations/{key}', [SystemConfigurationController::class, 'update']);

        // User management — destructive/admin-only actions
        Route::put('/users/{user}', [UserManagementController::class, 'update']);
        Route::post('/users/{user}/deactivate', [UserManagementController::class, 'deactivate']);
        Route::post('/users/{user}/reactivate', [UserManagementController::class, 'reactivate'])->withTrashed();
        Route::post('/users/{user}/reset-password', [UserManagementController::class, 'sendResetEmail']);
        Route::post('/users/{user}/branches', [UserManagementController::class, 'assignBranch']);
        Route::delete('/users/{user}/branches/{branch}', [UserManagementController::class, 'detachBranch']);
    });

    // User management — list, view, create, and photo upload — admin and manager
    Route::middleware('role:admin|manager')->group(function () {
        Route::get('/users', [UserManagementController::class, 'index']);
        Route::get('/users/{user}', [UserManagementController::class, 'show']);
        Route::post('/users', [UserManagementController::class, 'store']);
        Route::post('/users/{user}/photo', [UserManagementController::class, 'uploadPhoto']);
    });

    // Enrollment & Students — admin, manager, supervisor
    Route::middleware('role:admin|manager|supervisor')->group(function () {
        Route::get('/enrollment', [EnrollmentController::class, 'index']);
        Route::post('/enrollment', [EnrollmentController::class, 'store']);

        Route::get('/students', [StudentController::class, 'index']);
        Route::get('/students/{student}', [StudentController::class, 'show']);
        Route::put('/students/{student}', [StudentController::class, 'update']);
        Route::delete('/students/{student}', [StudentController::class, 'destroy']);
        Route::post('/students/{student}/regenerate-qr', [StudentController::class, 'regenerateQr']);
        Route::patch('/students/{student}/status', [StudentController::class, 'updateStatus']);
        Route::patch('/students/{student}/type', [StudentController::class, 'updateType']);
        Route::get('/students/{student}/orders', [StudentController::class, 'orders']);
        Route::post('/students/{student}/wallet/top-up', [WalletController::class, 'topUp']);
        Route::get('/students/{student}/payments', [PaymentController::class, 'index']);

        // Student contacts
        Route::get('/students/{student}/contacts', [StudentContactController::class, 'index']);
        Route::post('/students/{student}/contacts', [StudentContactController::class, 'store']);
        Route::put('/students/{student}/contacts/{contact}', [StudentContactController::class, 'update']);
        Route::delete('/students/{student}/contacts/{contact}', [StudentContactController::class, 'destroy']);
        Route::post('/students/{student}/contacts/{contact}/resend-activation', [StudentContactController::class, 'resendActivation']);
    });

    // Parent management — admin, manager only
    Route::middleware('role:admin|manager')->group(function () {
        Route::get('/references/parents', [ParentController::class, 'index']);
        Route::get('/references/parents/{parent}', [ParentController::class, 'show']);
        Route::post('/references/parents/{parent}/resend-activation', [ParentController::class, 'resendActivation']);
    });

    // Feedback — admin, manager, supervisor
    Route::middleware('role:admin|manager|supervisor')->group(function () {
        Route::get('/references/feedback', [FeedbackController::class, 'index']);
        Route::post('/references/feedback/{feedback}/reply', [FeedbackController::class, 'reply']);
        Route::patch('/references/feedback/{feedback}/mark-read', [FeedbackController::class, 'markRead']);
    });

    // Payment toggle/record + credit settle — admin, manager only
    Route::middleware('role:admin|manager')->group(function () {
        Route::patch('/students/{student}/payments/{payment}', [PaymentController::class, 'toggle']);
        Route::patch('/students/{student}/payments/{payment}/amount', [PaymentController::class, 'updateAmount']);
        Route::post('/students/{student}/payments', [PaymentController::class, 'record']);
        Route::post('/students/{student}/credit/settle', [CreditController::class, 'settle']);
    });

    // Branch monthly amounts — admin, manager, supervisor
    Route::middleware('role:admin|manager|supervisor')->group(function () {
        Route::get('/branch-monthly-amounts', [BranchMonthlyAmountController::class, 'index']);
        Route::post('/branch-monthly-amounts', [BranchMonthlyAmountController::class, 'store']);
        Route::put('/branch-monthly-amounts/{branchMonthlyAmount}', [BranchMonthlyAmountController::class, 'update']);
        Route::delete('/branch-monthly-amounts/{branchMonthlyAmount}', [BranchMonthlyAmountController::class, 'destroy']);
        Route::post('/students/{student}/payments/range', [PaymentController::class, 'addRange']);
    });
});
