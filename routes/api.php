<?php

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
});
