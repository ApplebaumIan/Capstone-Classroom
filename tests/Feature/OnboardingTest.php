<?php

use App\GroupStatus;
use App\Jobs\ProvisionClassroomGroup;
use App\Jobs\RemoveStudentFromGitHubTeam;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\RosterEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

function rosterCsv(): string
{
    return <<<'CSV'
name,canvas_user_id,user_id,login_id,sections,group_name,canvas_group_id,group_id
"Tan, Timmy",265892,tul26954,tul26954,Section: 002,Section 002: Vulnhunter,404005,
Jane Doe,265893,tul26955,tul26955,Section: 002,Section 002: Vulnhunter,404005,
CSV;
}

test('dashboard creates one classroom for a teacher', function () {
    $teacher = User::factory()->create();

    $response = $this->actingAs($teacher)->get(route('dashboard'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('mode', 'teacher')
        ->where('classroom.installed', false));
    expect($teacher->fresh()->classroom)->not->toBeNull();
});

test('teacher imports quoted Canvas roster into groups', function () {
    $classroom = Classroom::factory()->installed()->create();
    $roster = UploadedFile::fake()->createWithContent('roster.csv', rosterCsv());

    $response = $this->actingAs($classroom->teacher)->post(route('roster.store'), [
        'roster' => $roster,
        'repository_visibility' => 'private',
    ]);

    $response->assertRedirect(route('dashboard'));
    $this->assertDatabaseHas('roster_entries', [
        'classroom_id' => $classroom->id,
        'name' => 'Tan, Timmy',
        'canvas_user_id' => '265892',
        'canvas_id' => 'tul26954',
    ]);
    $this->assertDatabaseCount('classroom_groups', 1);
    $this->assertDatabaseCount('roster_entries', 2);
});

test('roster import rejects unexpected headers', function () {
    $classroom = Classroom::factory()->installed()->create();
    $roster = UploadedFile::fake()->createWithContent('roster.csv', "name,group\nTimmy,Vulnhunter");

    $response = $this->actingAs($classroom->teacher)->post(route('roster.store'), [
        'roster' => $roster,
        'repository_visibility' => 'private',
    ]);

    $response->assertSessionHasErrors([
        'roster' => 'The roster headers must exactly match the Canvas group export format.',
    ]);
    $this->assertDatabaseCount('roster_entries', 0);
});

test('student claims one roster entry and queues group provisioning', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $entry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create();
    $student = User::factory()->create();

    $response = $this->actingAs($student)->post(route('roster-claims.store', [$classroom->join_code, $entry]));

    $response->assertRedirect(route('classrooms.join', $classroom->join_code));
    expect($entry->fresh()->claimed_by_user_id)->toBe($student->id)
        ->and($group->fresh()->status)->toBe(GroupStatus::Provisioning);
    Queue::assertPushed(ProvisionClassroomGroup::class, fn ($job) => $job->classroomGroupId === $group->id);
});

test('student cannot claim a second roster entry', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $student = User::factory()->create();
    RosterEntry::factory()->for($classroom)->for($group, 'group')->create([
        'claimed_by_user_id' => $student->id,
        'claimed_at' => now(),
    ]);
    $otherEntry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create();

    $response = $this->actingAs($student)->post(route('roster-claims.store', [$classroom->join_code, $otherEntry]));

    $response->assertSessionHasErrors(['roster_entry' => 'You have already claimed a roster entry.']);
    expect($otherEntry->fresh()->claimed_by_user_id)->toBeNull();
    Queue::assertNothingPushed();
});

test('teacher resets a claim and queues GitHub team removal', function () {
    Queue::fake([RemoveStudentFromGitHubTeam::class]);
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create(['github_team_slug' => 'vulnhunter']);
    $student = User::factory()->create(['github_login' => 'octocat']);
    $entry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create([
        'claimed_by_user_id' => $student->id,
        'claimed_at' => now(),
    ]);

    $response = $this->actingAs($classroom->teacher)->delete(route('roster-claims.destroy', $entry));

    $response->assertRedirect(route('dashboard'));
    expect($entry->fresh()->claimed_by_user_id)->toBeNull();
    Queue::assertPushed(RemoveStudentFromGitHubTeam::class, fn ($job) => $job->githubLogin === 'octocat');
});

test('another teacher cannot reset a roster claim', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $entry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create();
    $otherTeacher = User::factory()->create();

    $response = $this->actingAs($otherTeacher)->delete(route('roster-claims.destroy', $entry));

    $response->assertNotFound();
});

test('teacher connects only a GitHub installation available to their account', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/user/installations*' => Http::response([
            'installations' => [[
                'id' => 123,
                'target_type' => 'Organization',
                'account' => ['id' => 456, 'login' => 'temple'],
            ]],
        ]),
    ]);
    $classroom = Classroom::factory()->create();

    $response = $this->actingAs($classroom->teacher)
        ->withSession(['github.user_access_token' => Crypt::encryptString('user-token')])
        ->post(route('github.installations.store'), ['installation_id' => '123']);

    $response->assertRedirect(route('dashboard'));
    expect($classroom->fresh())
        ->github_installation_id->toBe('123')
        ->github_organization_login->toBe('temple');
});

test('teacher cannot connect a spoofed GitHub installation', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/user/installations*' => Http::response(['installations' => []]),
    ]);
    $classroom = Classroom::factory()->create();

    $response = $this->actingAs($classroom->teacher)
        ->withSession(['github.user_access_token' => Crypt::encryptString('user-token')])
        ->post(route('github.installations.store'), ['installation_id' => '999']);

    $response->assertSessionHasErrors([
        'installation_id' => 'That GitHub App installation is not available to your account.',
    ]);
    expect($classroom->fresh()->github_installation_id)->toBeNull();
});
