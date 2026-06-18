<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HomeOpsDashboardController;


Route::get('/', function () {
    return view('welcome');
});

Route::prefix('homeops')->group(function () {
    Route::get('/dashboard', [HomeOpsDashboardController::class, 'index']);
});
