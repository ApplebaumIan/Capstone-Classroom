<?php

use App\Http\Controllers\Auth\GitHubAuthenticationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

Route::middleware('guest')->group(function () {
    Route::get('/auth/github', [GitHubAuthenticationController::class, 'redirect'])
        ->name('github.redirect');
    Route::get('/auth/github/callback', [GitHubAuthenticationController::class, 'callback'])
        ->name('github.callback');
});

require __DIR__.'/settings.php';
