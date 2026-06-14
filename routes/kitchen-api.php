<?php

use App\Http\Controllers\Kitchen\ActivityLogController;
use App\Http\Controllers\Kitchen\AnnouncementController;
use App\Http\Controllers\Kitchen\AuthController;
use App\Http\Controllers\Kitchen\BillingReportController;
use App\Http\Controllers\Kitchen\BranchController;
use App\Http\Controllers\Kitchen\BranchMonthlyAmountController;
use App\Http\Controllers\Kitchen\CheckoutController;
use App\Http\Controllers\Kitchen\CreditController;
use App\Http\Controllers\Kitchen\CreditReportController;
use App\Http\Controllers\Kitchen\DailySummaryController;
use App\Http\Controllers\Kitchen\DashboardController;
use App\Http\Controllers\Kitchen\EnrollmentController;
use App\Http\Controllers\Kitchen\FeedbackController;
use App\Http\Controllers\Kitchen\InlineReloadController;
use App\Http\Controllers\Kitchen\InventoryController;
use App\Http\Controllers\Kitchen\InventoryIngredientController;
use App\Http\Controllers\Kitchen\InventoryReportController;
use App\Http\Controllers\Kitchen\MealPlannerController;
use App\Http\Controllers\Kitchen\ParentController;
use App\Http\Controllers\Kitchen\PaymentController;
use App\Http\Controllers\Kitchen\PosMenuItemController;
use App\Http\Controllers\Kitchen\PreRegistrationController;
use App\Http\Controllers\Kitchen\ReminderController;
use App\Http\Controllers\Kitchen\SalesReportController;
use App\Http\Controllers\Kitchen\StaffNotificationController;
use App\Http\Controllers\Kitchen\StudentContactController;
use App\Http\Controllers\Kitchen\StudentController;
use App\Http\Controllers\Kitchen\StudentLookupController;
use App\Http\Controllers\Kitchen\StudentReportController;
use App\Http\Controllers\Kitchen\SubscriptionConfigController;
use App\Http\Controllers\Kitchen\SubscriptionReportController;
use App\Http\Controllers\Kitchen\SystemConfigurationController;
use App\Http\Controllers\Kitchen\TransactionController;
use App\Http\Controllers\Kitchen\UserManagementController;
use App\Http\Controllers\Kitchen\WalletController;
use App\Http\Controllers\Kitchen\WalletReportController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

// Staff auth — public (rate limited)
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/auth/password/email', [AuthController::class, 'sendResetEmail'])->middleware('throttle:password-reset');
Route::post('/auth/password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');

// Staff auth — authenticated
Route::middleware(['auth:sanctum', 'ability:staff'])->group(function () {
    Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate']);

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

        // Subscription config — admin/manager only
        Route::get('/pos/subscription-config', [SubscriptionConfigController::class, 'show']);
        Route::put('/pos/subscription-config', [SubscriptionConfigController::class, 'update']);
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

    // Meal Planner — view for all staff; edit/reset/visibility for admin, manager only
    Route::get('/references/meal-planner', [MealPlannerController::class, 'show']);
    Route::middleware('role:admin|manager')->group(function () {
        Route::patch('/references/meal-planner/week-visibility', [MealPlannerController::class, 'updateWeekVisibility']);
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
        // NOTE: /history must be defined before /{item} to avoid route conflict
        Route::get('/references/inventory/history', [InventoryController::class, 'history']);
        Route::put('/references/inventory/{item}', [InventoryController::class, 'update']);
        Route::delete('/references/inventory/{item}', [InventoryController::class, 'destroy']);
        Route::get('/references/inventory/{item}/logs', [InventoryController::class, 'logs']);
        // Ingredient mapping — read access for admin|manager|supervisor
        Route::get('/references/menu-items/{item}/ingredients', [InventoryIngredientController::class, 'index']);
    });

    // Inventory archive/unarchive + ingredient mutations — admin, manager only
    Route::middleware('role:admin|manager')->group(function () {
        Route::patch('/references/inventory/{item}/archive', [InventoryController::class, 'archive']);
        Route::patch('/references/inventory/{item}/unarchive', [InventoryController::class, 'unarchive']);
        Route::post('/references/menu-items/{item}/ingredients', [InventoryIngredientController::class, 'attach']);
        Route::delete('/references/menu-items/{item}/ingredients/{inventoryItem}', [InventoryIngredientController::class, 'detach']);
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
        Route::get('/students/{student}/photo', [StudentController::class, 'photo']);
        Route::post('/students/{student}/photo', [StudentController::class, 'uploadPhoto']);
        Route::put('/students/{student}', [StudentController::class, 'update']);
        Route::delete('/students/{student}', [StudentController::class, 'destroy']);
        Route::post('/students/{student}/restore', [StudentController::class, 'restore'])->withTrashed();
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
        Route::post('/references/parents/{parent}/disable', [ParentController::class, 'disable']);
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

    // Dashboard — admin, manager, supervisor
    Route::middleware('role:admin|manager|supervisor')->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::post('/dashboard/staff-status', [DashboardController::class, 'updateStaffStatus']);
    });

    // Reminders — bell count visible to all staff; management restricted by role
    Route::get('/reminders/bell-count', [ReminderController::class, 'bellCount']);
    Route::middleware('role:admin|manager|supervisor')->group(function () {
        Route::get('/reminders/eligible-parents', [ReminderController::class, 'eligibleParents']);
        Route::get('/reminders/parents/{parent}', [ReminderController::class, 'show']);
    });
    Route::middleware('role:admin|manager')->group(function () {
        Route::post('/reminders/send', [ReminderController::class, 'send']);
    });

    // Staff notifications (inbox) — all staff roles
    Route::get('/staff/notifications/unread-count', [StaffNotificationController::class, 'unreadCount']);
    Route::get('/staff/notifications', [StaffNotificationController::class, 'index']);
    Route::post('/staff/notifications/mark-all-read', [StaffNotificationController::class, 'markAllRead']);
    Route::patch('/staff/notifications/{id}/read', [StaffNotificationController::class, 'markRead']);
    Route::delete('/staff/notifications/{id}', [StaffNotificationController::class, 'destroy']);

    // Announcements — supervisor+ can send; all supervisor+ can list/show
    Route::middleware('role:admin|manager|supervisor')->group(function () {
        Route::get('/announcements', [AnnouncementController::class, 'index']);
        Route::post('/announcements', [AnnouncementController::class, 'store']);
        Route::get('/announcements/{announcement}', [AnnouncementController::class, 'show']);
    });

    // Pre-Registrations
    Route::middleware('role:admin|manager|supervisor')->group(function () {
        Route::get('/pre-registrations', [PreRegistrationController::class, 'index']);
        Route::get('/pre-registrations/{preRegistration}', [PreRegistrationController::class, 'show']);
        Route::patch('/pre-registrations/{preRegistration}', [PreRegistrationController::class, 'update']);
        Route::post('/pre-registrations/{preRegistration}/reactivate', [PreRegistrationController::class, 'reactivate']);
    });

    Route::middleware('role:admin|manager')->group(function () {
        Route::post('/pre-registrations/{preRegistration}/approve', [PreRegistrationController::class, 'approve']);
        Route::post('/pre-registrations/{preRegistration}/reject', [PreRegistrationController::class, 'reject']);
    });

    // Reports
    Route::prefix('reports')->group(function () {
        Route::middleware('role:admin|manager|supervisor')->group(function () {
            Route::get('/sales', [SalesReportController::class, 'index']);
            Route::get('/students', [StudentReportController::class, 'index']);
            Route::get('/inventory', [InventoryReportController::class, 'index']);
            Route::get('/billing', [BillingReportController::class, 'index']);
            Route::get('/subscription', [SubscriptionReportController::class, 'index']);
        });

        Route::middleware('role:admin|manager')->group(function () {
            Route::get('/sales/export', [SalesReportController::class, 'export']);
            Route::get('/students/export', [StudentReportController::class, 'export']);
            Route::get('/wallet', [WalletReportController::class, 'index']);
            Route::get('/wallet/export', [WalletReportController::class, 'export']);
            Route::get('/inventory/export', [InventoryReportController::class, 'export']);
            Route::get('/daily-summary', [DailySummaryController::class, 'index']);
            Route::get('/activity', [ActivityLogController::class, 'index']);
            Route::get('/billing/export', [BillingReportController::class, 'export']);
            Route::get('/credits', [CreditReportController::class, 'index']);
        });
    });
});
