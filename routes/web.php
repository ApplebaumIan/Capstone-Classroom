<?php

use App\Http\Controllers\Auth\GitHubAuthenticationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GitHubInstallationController;
use App\Http\Controllers\GroupProvisioningController;
use App\Http\Controllers\RosterClaimController;
use App\Http\Controllers\RosterClaimResetController;
use App\Http\Controllers\RosterController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::inertia('/login', 'auth/login')->middleware('guest')->name('login');
Route::post('/logout', [GitHubAuthenticationController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('github/install', [GitHubInstallationController::class, 'create'])->name('github.installations.create');
    Route::post('github/install', [GitHubInstallationController::class, 'store'])->name('github.installations.store');
    Route::post('roster', [RosterController::class, 'store'])->name('roster.store');
    Route::get('join/{classroom:join_code}', [RosterClaimController::class, 'show'])->name('classrooms.join');
    Route::post('join/{classroom:join_code}/entries/{rosterEntry}', [RosterClaimController::class, 'store'])->name('roster-claims.store');
    Route::delete('roster-claims/{rosterEntry}', RosterClaimResetController::class)->name('roster-claims.destroy');
    Route::post('groups/{classroomGroup}/provisioning', GroupProvisioningController::class)->name('group-provisioning.store');
});

Route::get('/auth/github', [GitHubAuthenticationController::class, 'redirect'])
    ->middleware('guest')
    ->name('github.redirect');
Route::get('/auth/github/callback', [GitHubAuthenticationController::class, 'callback'])
    ->name('github.callback');
