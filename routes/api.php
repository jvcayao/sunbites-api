<?php

use App\Http\Controllers\Public\BranchController;
use App\Http\Controllers\Public\KioskLookupController;
use App\Http\Controllers\Public\PreRegistrationController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('health', function () {
    DB::connection()->getPdo();

    return response()->json(['status' => 'ok']);
});

Route::prefix('v1')->group(function () {
    require __DIR__.'/kitchen-api.php';

    Route::prefix('portal')->group(function () {
        require __DIR__.'/portal-api.php';
    });

    Route::prefix('public')->group(function () {
        Route::get('branches', [BranchController::class, 'index']);
        Route::post('pre-registrations', [PreRegistrationController::class, 'store'])
            ->middleware('throttle:3,60');
        Route::post('kiosk/lookup', [KioskLookupController::class, 'lookup'])
            ->middleware('throttle:10,1');
    });
});
