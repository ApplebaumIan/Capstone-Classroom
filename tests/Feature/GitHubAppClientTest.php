<?php

use App\GroupStatus;
use App\Jobs\ConfigureGitHubPages;
use App\Jobs\InitializeGitHubRepository;
use App\Jobs\ProvisionClassroomGroup;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\RosterEntry;
use App\Models\User;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

test('client returns only organization installations available to user', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/user/installations*' => Http::response([
            'installations' => [
                ['id' => 10, 'target_type' => 'Organization', 'account' => ['id' => 20, 'login' => 'temple']],
                ['id' => 11, 'target_type' => 'User', 'account' => ['id' => 21, 'login' => 'octocat']],
            ],
        ]),
    ]);

    $installations = (new GitHubAppClient)->accessibleInstallations('user-token');

    expect($installations)->toHaveCount(1)
        ->and($installations[0]['id'])->toBe(10);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer user-token'));
});

test('provisioning creates resources grants access and adds claimed students', function () {
    Queue::fake([InitializeGitHubRepository::class]);
    $classroom = Classroom::factory()->installed()->create(['github_organization_login' => 'temple']);
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $student = User::factory()->create(['github_login' => 'octocat']);
    RosterEntry::factory()->for($classroom)->for($group, 'group')->create(['claimed_by_user_id' => $student->id]);
    $github = mock(GitHubAppClient::class);
    $github->shouldReceive('ensureTeam')->once()->andReturn([
        'id' => 100,
        'slug' => 'vulnhunter',
        'html_url' => 'https://github.com/orgs/temple/teams/vulnhunter',
    ]);
    $github->shouldReceive('ensureRepository')->once()->andReturn([
        'id' => 200,
        'html_url' => 'https://github.com/temple/vulnhunter',
    ]);
    $github->shouldReceive('grantTeamRepository')->once();
    $github->shouldReceive('addTeamMember')->once()->withArgs(fn ($sentGroup, $login) => $sentGroup->id === $group->id && $login === 'octocat')->andReturn(['state' => 'pending']);

    (new ProvisionClassroomGroup($group->id))->handle($github);

    expect($group->fresh())
        ->github_team_id->toBe('100')
        ->github_repository_id->toBe('200')
        ->status->toBe(GroupStatus::Provisioning);
    Queue::assertPushed(InitializeGitHubRepository::class, fn ($job) => $job->classroomGroupId === $group->id);
});

test('provisioning reuses stored resources and preserves claimed students', function () {
    Queue::fake([InitializeGitHubRepository::class]);
    $classroom = Classroom::factory()->installed()->create(['github_organization_login' => 'temple']);
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'github_team_id' => '100',
        'github_team_slug' => 'vulnhunter',
        'github_team_url' => 'https://github.com/orgs/temple/teams/vulnhunter',
        'github_repository_id' => '200',
        'github_repository_url' => 'https://github.com/temple/vulnhunter',
    ]);
    $student = User::factory()->create(['github_login' => 'octocat']);
    $entry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create([
        'claimed_by_user_id' => $student->id,
    ]);
    $github = mock(GitHubAppClient::class);
    $github->shouldNotReceive('ensureTeam');
    $github->shouldNotReceive('ensureRepository');
    $github->shouldReceive('grantTeamRepository')->once();
    $github->shouldReceive('addTeamMember')->once()->withArgs(fn ($sentGroup, $login) => $sentGroup->id === $group->id && $login === 'octocat')->andReturn(['state' => 'active']);

    (new ProvisionClassroomGroup($group->id))->handle($github);

    $freshGroup = $group->fresh();

    expect($entry->fresh()->claimed_by_user_id)->toBe($student->id);
    expect($freshGroup->github_repository_id)->toBe('200')
        ->and($freshGroup->status)->toBe(GroupStatus::Provisioning);
    Queue::assertPushed(InitializeGitHubRepository::class, fn ($job) => $job->classroomGroupId === $group->id);
});

test('client grants team members admin privileges on their repository', function () {
    $classroom = Classroom::factory()->installed()->create([
        'github_organization_login' => 'temple',
        'github_installation_id' => '12345',
    ]);
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'github_team_slug' => 'vulnhunter',
        'repository_name' => 'vulnhunter-repository',
    ]);
    Cache::put('github-installation-token-12345', 'installation-token');
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/orgs/temple/teams/vulnhunter/repos/temple/vulnhunter-repository' => Http::response(status: 204),
    ]);

    (new GitHubAppClient)->grantTeamRepository($group);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://api.github.com/orgs/temple/teams/vulnhunter/repos/temple/vulnhunter-repository'
        && $request->hasHeader('Authorization', 'Bearer installation-token')
        && $request->data() === ['permission' => 'admin']);
});

test('client reports repository ready when template workflow exists', function () {
    $classroom = Classroom::factory()->installed()->create([
        'github_organization_login' => 'temple',
        'github_installation_id' => '12345',
    ]);
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'repository_name' => 'vulnhunter-repository',
        'github_repository_id' => '200',
    ]);
    Cache::put('github-installation-token-12345', 'installation-token');
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/repos/temple/vulnhunter-repository/contents/.github/workflows/deploy.yml' => Http::response([
            'type' => 'file',
            'path' => '.github/workflows/deploy.yml',
        ]),
    ]);

    $ready = (new GitHubAppClient)->hasRepositoryTemplateContents($group);

    expect($ready)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://api.github.com/repos/temple/vulnhunter-repository/contents/.github/workflows/deploy.yml');
});

test('client reports repository not ready when template workflow is missing', function () {
    $classroom = Classroom::factory()->installed()->create([
        'github_organization_login' => 'temple',
        'github_installation_id' => '12345',
    ]);
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'repository_name' => 'vulnhunter-repository',
        'github_repository_id' => '200',
    ]);
    Cache::put('github-installation-token-12345', 'installation-token');
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/repos/temple/vulnhunter-repository/contents/.github/workflows/deploy.yml' => Http::response(status: 404),
    ]);

    $ready = (new GitHubAppClient)->hasRepositoryTemplateContents($group);

    expect($ready)->toBeFalse();
    Http::assertSentCount(1);
});

test('client reports repository not ready when readiness path is not a file', function () {
    $classroom = Classroom::factory()->installed()->create([
        'github_organization_login' => 'temple',
        'github_installation_id' => '12345',
    ]);
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'repository_name' => 'vulnhunter-repository',
        'github_repository_id' => '200',
    ]);
    Cache::put('github-installation-token-12345', 'installation-token');
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/repos/temple/vulnhunter-repository/contents/.github/workflows/deploy.yml' => Http::response([
            'type' => 'dir',
        ]),
    ]);

    $ready = (new GitHubAppClient)->hasRepositoryTemplateContents($group);

    expect($ready)->toBeFalse();
    Http::assertSentCount(1);
});

test('client throws when repository readiness check returns an API error', function () {
    $classroom = Classroom::factory()->installed()->create([
        'github_organization_login' => 'temple',
        'github_installation_id' => '12345',
    ]);
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'repository_name' => 'vulnhunter-repository',
        'github_repository_id' => '200',
    ]);
    Cache::put('github-installation-token-12345', 'installation-token');
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/repos/temple/vulnhunter-repository/contents/.github/workflows/deploy.yml' => Http::response(['message' => 'Server Error'], 500),
    ]);

    expect(fn () => (new GitHubAppClient)->hasRepositoryTemplateContents($group))
        ->toThrow(RequestException::class);

    Http::assertSentCount(1);
});

test('client does not recreate an existing deployment marker', function () {
    $classroom = Classroom::factory()->installed()->create([
        'github_organization_login' => 'temple',
        'github_installation_id' => '12345',
    ]);
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'repository_name' => 'vulnhunter-repository',
        'github_repository_id' => '200',
    ]);
    Cache::put('github-installation-token-12345', 'installation-token');
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/repos/temple/vulnhunter-repository/contents/.capstone-classroom.json' => Http::response(['type' => 'file']),
    ]);

    (new GitHubAppClient)->triggerPagesDeployment($group);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
});

test('client creates deployment marker with classroom and group identity', function () {
    $classroom = Classroom::factory()->installed()->create([
        'join_code' => 'ABC123',
        'github_organization_login' => 'temple',
        'github_installation_id' => '12345',
    ]);
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'name' => 'Vuln Hunter',
        'repository_name' => 'vulnhunter-repository',
        'github_repository_id' => '200',
    ]);
    Cache::put('github-installation-token-12345', 'installation-token');
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => $request->method() === 'GET'
        ? Http::response(status: 404)
        : Http::response(['content' => ['sha' => 'marker-sha']], 201));

    (new GitHubAppClient)->triggerPagesDeployment($group);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://api.github.com/repos/temple/vulnhunter-repository/contents/.capstone-classroom.json'
        && $request->data() === [
            'message' => 'Initialize Capstone Classroom deployment',
            'content' => base64_encode("{\n    \"classroom\": \"ABC123\",\n    \"group\": \"Vuln Hunter\"\n}\n"),
        ]);
});

test('repository initialization waits without writing when template contents are missing', function () {
    Queue::fake([ConfigureGitHubPages::class]);
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'status' => GroupStatus::Provisioning,
        'github_repository_id' => '200',
        'provisioning_error' => 'Previous API error',
    ]);
    $github = mock(GitHubAppClient::class);
    $github->shouldReceive('hasRepositoryTemplateContents')->once()->andReturnFalse();
    $github->shouldNotReceive('triggerPagesDeployment');
    $job = (new InitializeGitHubRepository($group->id))->withFakeQueueInteractions();

    $job->handle($github);

    $job->assertReleased(delay: 30);
    $freshGroup = $group->fresh();

    expect($freshGroup->status)->toBe(GroupStatus::Provisioning)
        ->and($freshGroup->provisioning_error)->toBeNull();
    Queue::assertNotPushed(ConfigureGitHubPages::class);
});

test('repository initialization writes marker only after template contents appear', function () {
    Queue::fake([ConfigureGitHubPages::class]);
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'status' => GroupStatus::Provisioning,
        'github_repository_id' => '200',
        'provisioning_error' => 'Previous transient error',
    ]);
    $github = mock(GitHubAppClient::class);
    $github->shouldReceive('hasRepositoryTemplateContents')->once()->andReturnTrue();
    $github->shouldReceive('triggerPagesDeployment')->once();

    (new InitializeGitHubRepository($group->id))->handle($github);

    $freshGroup = $group->fresh();

    expect($freshGroup->status)->toBe(GroupStatus::Ready)
        ->and($freshGroup->provisioning_error)->toBeNull();
    Queue::assertPushed(ConfigureGitHubPages::class, fn ($job) => $job->classroomGroupId === $group->id);
});

test('repository initialization handles delayed GitHub template responses end to end', function () {
    Queue::fake([ConfigureGitHubPages::class]);
    $classroom = Classroom::factory()->installed()->create([
        'join_code' => 'ABC123',
        'github_organization_login' => 'temple',
        'github_installation_id' => '12345',
    ]);
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'name' => 'Vuln Hunter',
        'repository_name' => 'vulnhunter-repository',
        'status' => GroupStatus::Provisioning,
        'github_repository_id' => '200',
    ]);
    Cache::put('github-installation-token-12345', 'installation-token');
    Http::preventStrayRequests();
    $readinessChecks = 0;
    Http::fake(function (Request $request) use (&$readinessChecks) {
        if (str_ends_with($request->url(), '/contents/.github/workflows/deploy.yml')) {
            $readinessChecks++;

            return $readinessChecks === 1
                ? Http::response(status: 404)
                : Http::response(['type' => 'file']);
        }

        if (str_ends_with($request->url(), '/contents/.capstone-classroom.json')) {
            return $request->method() === 'GET'
                ? Http::response(status: 404)
                : Http::response(['content' => ['sha' => 'marker-sha']], 201);
        }

        return Http::response(status: 500);
    });
    $github = new GitHubAppClient;
    $waitingJob = (new InitializeGitHubRepository($group->id))->withFakeQueueInteractions();

    $waitingJob->handle($github);

    $waitingJob->assertReleased(delay: 30);
    expect($group->fresh()->status)->toBe(GroupStatus::Provisioning);

    (new InitializeGitHubRepository($group->id))->handle($github);

    expect($group->fresh()->status)->toBe(GroupStatus::Ready);
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/contents/.capstone-classroom.json'));
    Queue::assertPushed(ConfigureGitHubPages::class, fn ($job) => $job->classroomGroupId === $group->id);
});

test('repository initialization records transient readiness API errors while retrying', function () {
    Queue::fake([ConfigureGitHubPages::class]);
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'status' => GroupStatus::Provisioning,
        'github_repository_id' => '200',
    ]);
    $github = mock(GitHubAppClient::class);
    $github->shouldReceive('hasRepositoryTemplateContents')->once()->andThrow(new RuntimeException('GitHub unavailable.'));
    $github->shouldNotReceive('triggerPagesDeployment');

    expect(fn () => (new InitializeGitHubRepository($group->id))->handle($github))
        ->toThrow(RuntimeException::class, 'GitHub unavailable.');

    $freshGroup = $group->fresh();

    expect($freshGroup->status)->toBe(GroupStatus::Provisioning)
        ->and($freshGroup->provisioning_error)->toBe('GitHub unavailable.');
    Queue::assertNotPushed(ConfigureGitHubPages::class);
});

test('repository initialization honors GitHub retry delay when rate limited', function () {
    Queue::fake([ConfigureGitHubPages::class]);
    $classroom = Classroom::factory()->installed()->create([
        'github_organization_login' => 'temple',
        'github_installation_id' => '12345',
    ]);
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'repository_name' => 'vulnhunter-repository',
        'status' => GroupStatus::Provisioning,
        'github_repository_id' => '200',
    ]);
    Cache::put('github-installation-token-12345', 'installation-token');
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/repos/temple/vulnhunter-repository/contents/.github/workflows/deploy.yml' => Http::response(
            ['message' => 'API rate limit exceeded'],
            429,
            ['Retry-After' => '90'],
        ),
    ]);
    $job = (new InitializeGitHubRepository($group->id))->withFakeQueueInteractions();

    $job->handle(new GitHubAppClient);

    $job->assertReleased(delay: 90);
    $freshGroup = $group->fresh();

    expect($freshGroup->status)->toBe(GroupStatus::Provisioning)
        ->and($freshGroup->provisioning_error)->toContain('status code 429');
    Queue::assertNotPushed(ConfigureGitHubPages::class);
});

test('repository initialization honors GitHub retry delay for secondary rate limits', function () {
    Queue::fake([ConfigureGitHubPages::class]);
    $classroom = Classroom::factory()->installed()->create([
        'github_organization_login' => 'temple',
        'github_installation_id' => '12345',
    ]);
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'repository_name' => 'vulnhunter-repository',
        'status' => GroupStatus::Provisioning,
        'github_repository_id' => '200',
    ]);
    Cache::put('github-installation-token-12345', 'installation-token');
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/repos/temple/vulnhunter-repository/contents/.github/workflows/deploy.yml' => Http::response(
            ['message' => 'You have exceeded a secondary rate limit.'],
            403,
            ['Retry-After' => '75'],
        ),
    ]);
    $job = (new InitializeGitHubRepository($group->id))->withFakeQueueInteractions();

    $job->handle(new GitHubAppClient);

    $job->assertReleased(delay: 75);
    expect($group->fresh()->status)->toBe(GroupStatus::Provisioning);
    Queue::assertNotPushed(ConfigureGitHubPages::class);
});

test('repository initialization waits until GitHub primary rate limit resets', function () {
    $this->travelTo('2026-09-15 12:00:00');
    Queue::fake([ConfigureGitHubPages::class]);
    $classroom = Classroom::factory()->installed()->create([
        'github_organization_login' => 'temple',
        'github_installation_id' => '12345',
    ]);
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'repository_name' => 'vulnhunter-repository',
        'status' => GroupStatus::Provisioning,
        'github_repository_id' => '200',
    ]);
    Cache::put('github-installation-token-12345', 'installation-token');
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/repos/temple/vulnhunter-repository/contents/.github/workflows/deploy.yml' => Http::response(
            ['message' => 'API rate limit exceeded'],
            403,
            [
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) now()->addSeconds(120)->timestamp,
            ],
        ),
    ]);
    $job = (new InitializeGitHubRepository($group->id))->withFakeQueueInteractions();

    $job->handle(new GitHubAppClient);

    $job->assertReleased(delay: 120);
    expect($group->fresh()->status)->toBe(GroupStatus::Provisioning);
    Queue::assertNotPushed(ConfigureGitHubPages::class);
});

test('repository initialization records a specific error after readiness attempts expire', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'status' => GroupStatus::Provisioning,
        'github_repository_id' => '200',
    ]);

    (new InitializeGitHubRepository($group->id))->failed(new MaxAttemptsExceededException);

    $freshGroup = $group->fresh();

    expect($freshGroup->status)->toBe(GroupStatus::Failed)
        ->and($freshGroup->provisioning_error)->toBe('GitHub repository template contents did not become available in time.');
});

test('repository initialization records API failure details', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'status' => GroupStatus::Provisioning,
        'github_repository_id' => '200',
    ]);

    (new InitializeGitHubRepository($group->id))->failed(new RuntimeException('GitHub returned 500.'));

    $freshGroup = $group->fresh();

    expect($freshGroup->status)->toBe(GroupStatus::Failed)
        ->and($freshGroup->provisioning_error)->toBe('GitHub returned 500.');
});

test('repository initialization preserves API error details when attempts expire', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'status' => GroupStatus::Provisioning,
        'github_repository_id' => '200',
        'provisioning_error' => 'GitHub returned 500.',
    ]);

    (new InitializeGitHubRepository($group->id))->failed(new MaxAttemptsExceededException);

    $freshGroup = $group->fresh();

    expect($freshGroup->status)->toBe(GroupStatus::Failed)
        ->and($freshGroup->provisioning_error)->toBe('GitHub returned 500.');
});

test('repository initialization never becomes ready when marker write fails', function () {
    Queue::fake([ConfigureGitHubPages::class]);
    $classroom = Classroom::factory()->installed()->create([
        'github_organization_login' => 'temple',
        'github_installation_id' => '12345',
    ]);
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'repository_name' => 'vulnhunter-repository',
        'status' => GroupStatus::Provisioning,
        'github_repository_id' => '200',
    ]);
    Cache::put('github-installation-token-12345', 'installation-token');
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/contents/.github/workflows/deploy.yml')) {
            return Http::response(['type' => 'file']);
        }

        return $request->method() === 'GET'
            ? Http::response(status: 404)
            : Http::response(['message' => 'Server Error'], 500);
    });

    expect(fn () => (new InitializeGitHubRepository($group->id))->handle(new GitHubAppClient))
        ->toThrow(RequestException::class);

    $freshGroup = $group->fresh();

    expect($freshGroup->status)->toBe(GroupStatus::Provisioning)
        ->and($freshGroup->provisioning_error)->toContain('status code 500');
    Queue::assertNotPushed(ConfigureGitHubPages::class);
});

test('pages configuration waits while deployment branch is missing', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create(['status' => GroupStatus::Ready]);
    $github = mock(GitHubAppClient::class);
    $github->shouldReceive('hasPagesBranch')->once()->andReturnFalse();
    $github->shouldNotReceive('configurePages');
    $job = (new ConfigureGitHubPages($group->id))->withFakeQueueInteractions();

    $job->handle($github);

    $job->assertReleased(delay: 30);
    $freshGroup = $group->fresh();

    expect($freshGroup->status)->toBe(GroupStatus::Ready)
        ->and($freshGroup->github_pages_url)->toBeNull();
});

test('pages configuration stores deployed site URL', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'status' => GroupStatus::Ready,
        'provisioning_error' => 'Previous Pages error',
    ]);
    $github = mock(GitHubAppClient::class);
    $github->shouldReceive('hasPagesBranch')->once()->andReturnTrue();
    $github->shouldReceive('configurePages')->once()->andReturn('https://temple.github.io/vulnhunter');

    (new ConfigureGitHubPages($group->id))->handle($github);

    $freshGroup = $group->fresh();

    expect($freshGroup->github_pages_url)->toBe('https://temple.github.io/vulnhunter')
        ->and($freshGroup->provisioning_error)->toBeNull()
        ->and($freshGroup->status)->toBe(GroupStatus::Ready);
});

test('pages configuration failure makes group retryable', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create(['status' => GroupStatus::Ready]);

    (new ConfigureGitHubPages($group->id))->failed(new RuntimeException('GitHub Pages configuration timed out.'));

    $freshGroup = $group->fresh();

    expect($freshGroup->status)->toBe(GroupStatus::Failed)
        ->and($freshGroup->provisioning_error)->toBe('GitHub Pages configuration timed out.');
});

test('teacher retry preserves repository and roster links', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'status' => GroupStatus::Failed,
        'github_team_id' => '100',
        'github_repository_id' => '200',
        'provisioning_error' => 'Template missing.',
    ]);
    $student = User::factory()->create();
    $entry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create([
        'claimed_by_user_id' => $student->id,
    ]);

    $this->actingAs($classroom->teacher)
        ->post(route('group-provisioning.store', [$classroom, $group]))
        ->assertRedirect(route('classrooms.teams', $classroom));

    $freshGroup = $group->fresh();

    expect($freshGroup->status)->toBe(GroupStatus::Provisioning)
        ->and($freshGroup->github_team_id)->toBe('100')
        ->and($freshGroup->github_repository_id)->toBe('200')
        ->and($freshGroup->provisioning_error)->toBeNull();
    expect($entry->fresh()->claimed_by_user_id)->toBe($student->id);
    Queue::assertPushed(ProvisionClassroomGroup::class, fn ($job) => $job->classroomGroupId === $group->id);
});
