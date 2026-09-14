<?php

use App\Jobs\ConfigureGitHubPages;
use App\Jobs\ProvisionClassroomGroup;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\RosterEntry;
use App\Models\User;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
    Queue::fake([ConfigureGitHubPages::class]);
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
    $github->shouldReceive('triggerPagesDeployment')->once();

    (new ProvisionClassroomGroup($group->id))->handle($github);

    expect($group->fresh())
        ->github_team_id->toBe('100')
        ->github_repository_id->toBe('200')
        ->status->value->toBe('ready');
    Queue::assertPushed(ConfigureGitHubPages::class, fn ($job) => $job->classroomGroupId === $group->id);
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
