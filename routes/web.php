<?php

use App\Http\Controllers\Auth\GitHubAuthenticationController;
use App\Http\Controllers\Auth\LocalAuthenticationController;
use App\Http\Controllers\ClassroomController;
use App\Http\Controllers\ClassroomGroupController;
use App\Http\Controllers\ClassroomStudentController;
use App\Http\Controllers\ClassroomTeamController;
use App\Http\Controllers\ClassroomTeamCreationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GitHubInstallationController;
use App\Http\Controllers\GitHubSyncIssueAcceptanceController;
use App\Http\Controllers\GitHubSyncIssueResynchronizationController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\GroupProvisioningController;
use App\Http\Controllers\PendingClassroomStudentController;
use App\Http\Controllers\PendingRosterClaimController;
use App\Http\Controllers\RosterClaimController;
use App\Http\Controllers\RosterClaimResetController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\RosterImportSkipController;
use App\Http\Controllers\StudentClassroomGroupController;
use App\Http\Controllers\StudentClassroomGroupMembershipController;
use App\Http\Middleware\VerifyGitHubWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');
Route::post('webhooks/github', GitHubWebhookController::class)
    ->middleware(VerifyGitHubWebhookSignature::class)
    ->name('github.webhooks.store');
Route::inertia('/login', 'auth/login', [
    'localAuthEnabled' => fn (): bool => app()->environment('local'),
])->middleware('guest')->name('login');
Route::post('/logout', [GitHubAuthenticationController::class, 'destroy'])->middleware('auth')->name('logout');
Route::post('/auth/local/{role}', LocalAuthenticationController::class)
    ->middleware('guest')
    ->whereIn('role', ['teacher', 'student', 'pending-student'])
    ->name('local.login');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('classrooms/create', [ClassroomController::class, 'create'])->name('classrooms.create');
    Route::post('classrooms', [ClassroomController::class, 'store'])->name('classrooms.store');
    Route::get('classrooms/{classroom}/setup', [ClassroomController::class, 'edit'])->name('classrooms.edit');
    Route::put('classrooms/{classroom}', [ClassroomController::class, 'update'])->name('classrooms.update');
    Route::delete('classrooms/{classroom}', [ClassroomController::class, 'destroy'])->name('classrooms.destroy');
    Route::get('classrooms/{classroom}/students', ClassroomStudentController::class)->name('classrooms.students');
    Route::get('classrooms/{classroom}/teams', ClassroomTeamController::class)->name('classrooms.teams');
    Route::get('github/install', [GitHubInstallationController::class, 'create'])->name('github.installations.create');
    Route::get('github/install/app', [GitHubInstallationController::class, 'edit'])->name('github.installations.edit');
    Route::post('github/installations', [GitHubInstallationController::class, 'store'])->name('github.installations.store');
    Route::post('classrooms/{classroom}/roster', [RosterController::class, 'store'])->name('roster.store');
    Route::post('classrooms/{classroom}/roster/import-skip', RosterImportSkipController::class)->name('roster-import-skips.store');
    Route::post('classrooms/{classroom}/groups', [ClassroomGroupController::class, 'store'])->name('classroom-groups.store');
    Route::patch('classrooms/{classroom}/team-creation', [ClassroomTeamCreationController::class, 'update'])->name('classroom-team-creation.update');
    Route::get('join/{classroom:join_code}', [RosterClaimController::class, 'show'])->name('classrooms.join');
    Route::post('join/{classroom:join_code}/groups', [StudentClassroomGroupController::class, 'store'])->name('student-classroom-groups.store');
    Route::post('join/{classroom:join_code}/groups/{classroomGroup}/membership', StudentClassroomGroupMembershipController::class)->name('student-classroom-group-memberships.store');
    Route::post('join/{classroom:join_code}/skip', [PendingClassroomStudentController::class, 'store'])->name('pending-classroom-students.store');
    Route::post('join/{classroom:join_code}/entries/{rosterEntry}', [RosterClaimController::class, 'store'])->name('roster-claims.store');
    Route::post('classrooms/{classroom}/pending-students/{pendingStudent}/roster-claim', PendingRosterClaimController::class)->name('pending-roster-claims.store');
    Route::delete('classrooms/{classroom}/roster-claims/{rosterEntry}', RosterClaimResetController::class)->name('roster-claims.destroy');
    Route::post('classrooms/{classroom}/groups/{classroomGroup}/provisioning', GroupProvisioningController::class)->name('group-provisioning.store');
    Route::post('classrooms/{classroom}/groups/{classroomGroup}/github-sync-issues/{githubSyncIssue}/acceptance', GitHubSyncIssueAcceptanceController::class)->name('github-sync-issue-acceptances.store');
    Route::post('classrooms/{classroom}/groups/{classroomGroup}/github-sync-issues/{githubSyncIssue}/resynchronization', GitHubSyncIssueResynchronizationController::class)->name('github-sync-issue-resynchronizations.store');
});

Route::get('/auth/github', [GitHubAuthenticationController::class, 'redirect'])
    ->middleware('guest')
    ->name('github.redirect');
Route::get('/auth/github/callback', [GitHubAuthenticationController::class, 'callback'])
    ->name('github.callback');
