<?php

use App\GroupStatus;
use App\Models\Classroom;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('local database seeding creates an installed classroom with representative roster states', function () {
    $this->app['env'] = 'local';

    $this->seed(DatabaseSeeder::class);

    $classroom = Classroom::query()->firstOrFail();

    expect($classroom->github_installation_id)->toBe('local-installation')
        ->and($classroom->github_organization_login)->toBe('temple-capstone')
        ->and($classroom->roster_imported_at)->not->toBeNull()
        ->and($classroom->groups()->count())->toBe(3)
        ->and($classroom->groups()->where('repository_name', 'local-demo-team')->firstOrFail()->created_manually)->toBeTrue()
        ->and($classroom->groups()->pluck('status'))->toContain(
            GroupStatus::Ready,
            GroupStatus::Provisioning,
            GroupStatus::Failed,
        )
        ->and($classroom->rosterEntries()->count())->toBe(6)
        ->and($classroom->rosterEntries()->whereNotNull('claimed_by_user_id')->count())->toBe(3)
        ->and($classroom->pendingStudents()->where('github_id', 'sam-rivera')->exists())->toBeTrue()
        ->and(User::query()->where('github_id', 'local-teacher')->exists())->toBeTrue()
        ->and(User::query()->where('github_id', 'local-student')->exists())->toBeTrue();
});
