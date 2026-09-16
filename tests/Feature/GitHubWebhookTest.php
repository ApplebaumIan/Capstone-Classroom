<?php

use App\GitHubInstallationStatus;
use App\GitHubSyncIssueType;
use App\GitHubSyncResolution;
use App\GroupStatus;
use App\Jobs\ProvisionClassroomGroup;
use App\Jobs\ReconcileGitHubSyncIssue;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\GitHubSyncIssue;
use App\Models\RosterEntry;
use App\Models\User;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $payload */
function deliverGitHubWebhook(string $event, string $deliveryId, array $payload, string $secret = 'webhook-secret'): TestResponse
{
    config(['services.github.webhook_secret' => 'webhook-secret']);
    $content = json_encode($payload, JSON_THROW_ON_ERROR);

    return test()->call('POST', route('github.webhooks.store'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_GITHUB_DELIVERY' => $deliveryId,
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $content, $secret),
    ], $content);
}

function installedGroup(array $attributes = []): ClassroomGroup
{
    $classroom = Classroom::factory()->installed()->create([
        'github_installation_id' => '12345',
    ]);

    return ClassroomGroup::factory()->for($classroom)->create([
        'status' => GroupStatus::Ready,
        'github_team_id' => '100',
        'github_team_name' => 'Project Atlas',
        'github_team_slug' => 'project-atlas',
        'github_team_url' => 'https://github.com/orgs/temple/teams/project-atlas',
        'github_team_repository_access' => true,
        'github_repository_id' => '200',
        'github_repository_url' => 'https://github.com/temple/project-atlas',
        'github_pages_url' => 'https://temple.github.io/project-atlas',
        ...$attributes,
    ]);
}

test('repository deletion marks repository missing without automatically provisioning', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $group = installedGroup();
    $payload = [
        'action' => 'deleted',
        'installation' => ['id' => 12345],
        'repository' => ['id' => 200, 'name' => 'project-atlas'],
    ];

    deliverGitHubWebhook('repository', 'delivery-1', $payload)->assertNoContent();
    deliverGitHubWebhook('repository', 'delivery-1', $payload)->assertNoContent();

    $group->refresh();
    expect($group->status)->toBe(GroupStatus::Missing)
        ->and($group->github_repository_id)->toBeNull()
        ->and($group->github_repository_url)->toBeNull()
        ->and($group->github_pages_url)->toBeNull()
        ->and($group->github_repository_missing_at)->not->toBeNull()
        ->and($group->github_team_id)->toBe('100');
    expect(GitHubSyncIssue::query()->where('type', GitHubSyncIssueType::RepositoryDeleted)->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('webhook rejects an invalid signature without changing resources', function () {
    $group = installedGroup();

    deliverGitHubWebhook('repository', 'delivery-2', [
        'action' => 'deleted',
        'installation' => ['id' => 12345],
        'repository' => ['id' => 200],
    ], 'wrong-secret')->assertUnauthorized();

    expect($group->fresh()->github_repository_id)->toBe('200');
    $this->assertDatabaseCount('github_sync_issues', 0);
});

test('webhook ignores a resource from another installation', function () {
    $group = installedGroup();

    deliverGitHubWebhook('repository', 'delivery-3', [
        'action' => 'deleted',
        'installation' => ['id' => 99999],
        'repository' => ['id' => 200],
    ])->assertNoContent();

    expect($group->fresh()->github_repository_id)->toBe('200');
    $this->assertDatabaseCount('github_sync_issues', 0);
});

test('installation suspension disables github changes until unsuspended', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $group = installedGroup();
    mock(GitHubAppClient::class)
        ->shouldReceive('installationStatus')
        ->times(3)
        ->andReturn(
            GitHubInstallationStatus::Suspended,
            GitHubInstallationStatus::Active,
            GitHubInstallationStatus::Deleted,
        );

    deliverGitHubWebhook('installation', 'delivery-installation-1', [
        'action' => 'suspend',
        'installation' => ['id' => 12345],
    ])->assertNoContent();

    expect($group->classroom->fresh()->github_installation_status)->toBe(GitHubInstallationStatus::Suspended);
    $this->actingAs($group->classroom->teacher)
        ->post(route('group-provisioning.store', [$group->classroom, $group]))
        ->assertConflict();
    Queue::assertNothingPushed();

    $issue = GitHubSyncIssue::factory()->for($group, 'classroomGroup')->create([
        'type' => GitHubSyncIssueType::MembershipAdded,
        'github_user_id' => '500',
        'github_login' => 'octocat',
    ]);
    $this->post(route('github-sync-issue-resynchronizations.store', [$group->classroom, $group, $issue]))
        ->assertConflict();

    deliverGitHubWebhook('installation', 'delivery-installation-2', [
        'action' => 'unsuspend',
        'installation' => ['id' => 12345],
    ])->assertNoContent();

    expect($group->classroom->fresh()->github_installation_status)->toBe(GitHubInstallationStatus::Active);

    deliverGitHubWebhook('installation', 'delivery-installation-3', [
        'action' => 'deleted',
        'installation' => ['id' => 12345],
    ])->assertNoContent();
    deliverGitHubWebhook('installation', 'delivery-installation-4', [
        'action' => 'unsuspend',
        'installation' => ['id' => 12345],
    ])->assertNoContent();

    expect($group->classroom->fresh()->github_installation_status)->toBe(GitHubInstallationStatus::Deleted);
});

test('team edits update remote metadata and removed repository access requires repair', function () {
    $group = installedGroup();

    deliverGitHubWebhook('team', 'delivery-4', [
        'action' => 'edited',
        'installation' => ['id' => 12345],
        'team' => [
            'id' => 100,
            'name' => 'Renamed on GitHub',
            'slug' => 'renamed-on-github',
            'html_url' => 'https://github.com/orgs/temple/teams/renamed-on-github',
        ],
    ])->assertNoContent();
    deliverGitHubWebhook('team', 'delivery-5', [
        'action' => 'removed_from_repository',
        'installation' => ['id' => 12345],
        'team' => ['id' => 100],
        'repository' => ['id' => 200],
    ])->assertNoContent();

    $group->refresh();
    expect($group->github_team_name)->toBe('Renamed on GitHub')
        ->and($group->github_team_slug)->toBe('renamed-on-github')
        ->and($group->github_team_repository_access)->toBeFalse()
        ->and($group->status)->toBe(GroupStatus::Missing);
    $this->assertDatabaseHas('github_sync_issues', [
        'classroom_group_id' => $group->id,
        'type' => GitHubSyncIssueType::TeamRepositoryAccessRemoved->value,
        'resolved_at' => null,
    ]);
});

test('membership events only create drift when github differs from classroom', function () {
    $group = installedGroup();
    $expectedStudent = User::factory()->create(['github_id' => '501', 'github_login' => 'expected']);
    RosterEntry::factory()->for($group->classroom)->for($group, 'group')->create([
        'claimed_by_user_id' => $expectedStudent->id,
    ]);

    deliverGitHubWebhook('membership', 'delivery-6', [
        'action' => 'added',
        'installation' => ['id' => 12345],
        'team' => ['id' => 100],
        'member' => ['id' => 501, 'login' => 'expected'],
    ])->assertNoContent();
    deliverGitHubWebhook('membership', 'delivery-7', [
        'action' => 'added',
        'installation' => ['id' => 12345],
        'team' => ['id' => 100],
        'member' => ['id' => 502, 'login' => 'unexpected'],
    ])->assertNoContent();

    $this->assertDatabaseMissing('github_sync_issues', ['github_user_id' => '501']);
    $this->assertDatabaseHas('github_sync_issues', [
        'classroom_group_id' => $group->id,
        'type' => GitHubSyncIssueType::MembershipAdded->value,
        'github_user_id' => '502',
        'github_login' => 'unexpected',
        'resolved_at' => null,
    ]);
});

test('teacher can accept github addition and move known student to team', function () {
    Queue::fake([ReconcileGitHubSyncIssue::class]);
    $targetGroup = installedGroup();
    $previousGroup = ClassroomGroup::factory()->for($targetGroup->classroom)->create([
        'github_team_slug' => 'previous-team',
    ]);
    $student = User::factory()->create(['github_id' => '503', 'github_login' => 'octocat']);
    $entry = RosterEntry::factory()->for($targetGroup->classroom)->for($previousGroup, 'group')->create([
        'claimed_by_user_id' => $student->id,
    ]);
    $issue = GitHubSyncIssue::factory()->for($targetGroup, 'classroomGroup')->create([
        'type' => GitHubSyncIssueType::MembershipAdded,
        'github_user_id' => '503',
        'github_login' => 'octocat',
    ]);
    mock(GitHubAppClient::class)->shouldReceive('teamHasMember')->once()->andReturnTrue();

    $this->actingAs($targetGroup->classroom->teacher)
        ->post(route('github-sync-issue-acceptances.store', [$targetGroup->classroom, $targetGroup, $issue]))
        ->assertRedirect();

    expect($entry->fresh()->classroom_group_id)->toBe($targetGroup->id)
        ->and($issue->fresh()->resolution)->toBe(GitHubSyncResolution::Accepted)
        ->and($issue->fresh()->resolved_at)->not->toBeNull();
    $reconciliationIssue = GitHubSyncIssue::query()
        ->where('classroom_group_id', $previousGroup->id)
        ->where('type', GitHubSyncIssueType::MembershipAdded)
        ->whereNull('resolved_at')
        ->firstOrFail();
    Queue::assertPushed(ReconcileGitHubSyncIssue::class, fn ($job) => $job->githubSyncIssueId === $reconciliationIssue->id);
});

test('teacher accepting github removal moves student to pending', function () {
    $group = installedGroup();
    $student = User::factory()->create(['github_id' => '504', 'github_login' => 'octocat']);
    $entry = RosterEntry::factory()->for($group->classroom)->for($group, 'group')->create([
        'claimed_by_user_id' => $student->id,
        'claimed_at' => now(),
    ]);
    $issue = GitHubSyncIssue::factory()->for($group, 'classroomGroup')->create([
        'type' => GitHubSyncIssueType::MembershipRemoved,
        'github_user_id' => '504',
        'github_login' => 'octocat',
    ]);
    mock(GitHubAppClient::class)->shouldReceive('teamHasMember')->once()->andReturnFalse();

    $this->actingAs($group->classroom->teacher)
        ->post(route('github-sync-issue-acceptances.store', [$group->classroom, $group, $issue]))
        ->assertRedirect();

    expect($entry->fresh()->claimed_by_user_id)->toBeNull()
        ->and($group->classroom->pendingStudents()->whereKey($student->id)->exists())->toBeTrue()
        ->and($issue->fresh()->resolution)->toBe(GitHubSyncResolution::Accepted);
});

test('teacher can resynchronize membership drift but another teacher cannot', function () {
    Queue::fake([ReconcileGitHubSyncIssue::class]);
    $group = installedGroup();
    $issue = GitHubSyncIssue::factory()->for($group, 'classroomGroup')->create([
        'type' => GitHubSyncIssueType::MembershipAdded,
        'github_user_id' => '505',
        'github_login' => 'octocat',
    ]);

    $this->actingAs(User::factory()->create())
        ->post(route('github-sync-issue-resynchronizations.store', [$group->classroom, $group, $issue]))
        ->assertNotFound();

    $this->actingAs($group->classroom->teacher)
        ->post(route('github-sync-issue-resynchronizations.store', [$group->classroom, $group, $issue]))
        ->assertRedirect();

    Queue::assertPushed(ReconcileGitHubSyncIssue::class, fn ($job) => $job->githubSyncIssueId === $issue->id);
});

test('membership reconciliation resolves issue only after github succeeds', function () {
    $group = installedGroup();
    $issue = GitHubSyncIssue::factory()->for($group, 'classroomGroup')->create([
        'type' => GitHubSyncIssueType::MembershipRemoved,
        'github_user_id' => '507',
        'github_login' => 'octocat',
    ]);
    $github = mock(GitHubAppClient::class);
    $github->shouldReceive('addTeamMember')->once()->withArgs(
        fn ($sentGroup, $login): bool => $sentGroup->id === $group->id && $login === 'octocat',
    )->andReturn(['state' => 'active']);

    (new ReconcileGitHubSyncIssue($issue->id))->handle($github);

    expect($issue->fresh()->resolution)->toBe(GitHubSyncResolution::Resynced)
        ->and($issue->fresh()->resolved_at)->not->toBeNull();
});

test('teacher testing membership cannot be accepted as removed', function () {
    $group = installedGroup();
    $teacher = $group->classroom->teacher;
    $teacher->update(['github_id' => '508', 'github_login' => 'teacher']);
    RosterEntry::factory()->for($group->classroom)->for($group, 'group')->create([
        'claimed_by_user_id' => $teacher->id,
        'canvas_user_id' => "github-user-{$teacher->id}",
    ]);
    $issue = GitHubSyncIssue::factory()->for($group, 'classroomGroup')->create([
        'type' => GitHubSyncIssueType::MembershipRemoved,
        'github_user_id' => '508',
        'github_login' => 'teacher',
    ]);
    mock(GitHubAppClient::class)->shouldReceive('teamHasMember')->once()->andReturnFalse();

    $this->actingAs($teacher)
        ->post(route('github-sync-issue-acceptances.store', [$group->classroom, $group, $issue]))
        ->assertConflict();

    expect($issue->fresh()->resolved_at)->toBeNull()
        ->and($group->rosterEntries()->where('claimed_by_user_id', $teacher->id)->exists())->toBeTrue();
});

test('accepting obsolete membership drift resolves it without changing classroom', function () {
    $group = installedGroup();
    $student = User::factory()->create(['github_id' => '509', 'github_login' => 'octocat']);
    $entry = RosterEntry::factory()->for($group->classroom)->for($group, 'group')->create([
        'claimed_by_user_id' => $student->id,
    ]);
    $issue = GitHubSyncIssue::factory()->for($group, 'classroomGroup')->create([
        'type' => GitHubSyncIssueType::MembershipRemoved,
        'github_user_id' => '509',
        'github_login' => 'octocat',
    ]);
    mock(GitHubAppClient::class)->shouldReceive('teamHasMember')->once()->andReturnTrue();

    $this->actingAs($group->classroom->teacher)
        ->post(route('github-sync-issue-acceptances.store', [$group->classroom, $group, $issue]))
        ->assertRedirect();

    expect($entry->fresh()->claimed_by_user_id)->toBe($student->id)
        ->and($issue->fresh()->resolution)->toBe(GitHubSyncResolution::Resynced);
});

test('teams page exposes missing resources and actionable membership drift', function () {
    $group = installedGroup([
        'status' => GroupStatus::Missing,
        'github_repository_id' => null,
        'github_repository_url' => null,
        'github_repository_missing_at' => now(),
    ]);
    $student = User::factory()->create(['github_id' => '506', 'github_login' => 'octocat']);
    $group->classroom->pendingStudents()->attach($student);
    GitHubSyncIssue::factory()->for($group, 'classroomGroup')->create([
        'type' => GitHubSyncIssueType::MembershipAdded,
        'github_user_id' => '506',
        'github_login' => 'octocat',
    ]);

    $this->actingAs($group->classroom->teacher)
        ->get(route('classrooms.teams', $group->classroom))
        ->assertInertia(fn (Assert $page) => $page
            ->where('classroom.groups.0.status', 'missing')
            ->where('classroom.groups.0.repository_missing', true)
            ->where('classroom.groups.0.sync_issues.0.github_login', 'octocat')
            ->where('classroom.groups.0.sync_issues.0.can_accept', true));
});
