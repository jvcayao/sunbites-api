<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    require __DIR__.'/kitchen-api.php';

    Route::prefix('portal')->group(function () {
        require __DIR__.'/portal-api.php';
    });
});
