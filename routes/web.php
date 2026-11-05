<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemonstrationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// There is intentionally no registration or password-reset route:
// accounts are created by administrators (or `php artisan sqliguard:create-admin`).
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Shared SQL injection demonstrations (both roles). Each run opens a fresh
    // lab database connection, so execution is rate limited per user.
    Route::middleware('role:administrator,student')->prefix('demonstrations')->name('demonstrations.')->group(function () {
        Route::get('/', [DemonstrationController::class, 'index'])->name('index');
        Route::get('{demonstration}', [DemonstrationController::class, 'show'])->name('show');
        Route::post('{demonstration}', [DemonstrationController::class, 'run'])
            ->middleware('throttle:demonstrations')
            ->name('run');
    });

    Route::middleware('role:student')->prefix('student')->name('student.')->group(function () {
        Route::view('dashboard', 'student.dashboard')->name('dashboard');
    });

    Route::middleware('role:administrator')->prefix('admin')->name('admin.')->group(function () {
        Route::view('dashboard', 'admin.dashboard')->name('dashboard');

        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::patch('users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
        Route::patch('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    });
});
