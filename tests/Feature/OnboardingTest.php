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
    $classroom = Classroom::factory()->installed()->create(['roster_skipped_at' => now()]);
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
    expect($classroom->fresh()->roster_skipped_at)->toBeNull();
});

test('teacher can skip roster import and return to it later', function () {
    $classroom = Classroom::factory()->installed()->create();

    $response = $this->actingAs($classroom->teacher)->post(route('roster-import-skips.store'));

    $response->assertRedirect(route('dashboard'));
    expect($classroom->fresh()->roster_skipped_at)->not->toBeNull()
        ->and($classroom->fresh()->roster_imported_at)->toBeNull();
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('classroom.roster_skipped', true)
            ->where('classroom.roster_imported', false));
});

test('teacher cannot skip roster import before installing github', function () {
    $classroom = Classroom::factory()->create();

    $response = $this->actingAs($classroom->teacher)->post(route('roster-import-skips.store'));

    $response->assertConflict();
    expect($classroom->fresh()->roster_skipped_at)->toBeNull();
});

test('teacher can create a team without a roster', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create();

    $response = $this->actingAs($classroom->teacher)->post(route('classroom-groups.store'), [
        'name' => 'Project Atlas',
    ]);

    $response->assertRedirect(route('dashboard'));
    $group = $classroom->groups()->firstOrFail();
    expect($group->name)->toBe('Project Atlas')
        ->and($group->repository_name)->toBe('project-atlas')
        ->and($group->created_manually)->toBeTrue()
        ->and($classroom->fresh()->roster_skipped_at)->not->toBeNull();
    Queue::assertPushed(ProvisionClassroomGroup::class, fn ($job) => $job->classroomGroupId === $group->id);
});

test('student can create a team when classroom setting is enabled', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create();
    $student = User::factory()->create();
    $classroom->pendingStudents()->attach($student);

    $response = $this->actingAs($student)->post(route('student-classroom-groups.store', $classroom->join_code), [
        'name' => 'Student Project',
    ]);

    $response->assertRedirect(route('dashboard'));
    $group = $classroom->groups()->firstOrFail();
    expect($group->created_manually)->toBeTrue()
        ->and($group->rosterEntries()->firstOrFail()->claimed_by_user_id)->toBe($student->id)
        ->and($classroom->pendingStudents()->whereKey($student->id)->exists())->toBeFalse();
    Queue::assertPushed(ProvisionClassroomGroup::class, fn ($job) => $job->classroomGroupId === $group->id);
});

test('student can join an existing manually created team', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'created_manually' => true,
        'status' => GroupStatus::Ready,
    ]);
    $student = User::factory()->create();
    $classroom->pendingStudents()->attach($student);

    $response = $this->actingAs($student)->post(route('student-classroom-group-memberships.store', [
        $classroom->join_code,
        $group,
    ]));

    $response->assertRedirect(route('dashboard'));
    expect($group->rosterEntries()->firstOrFail()->claimed_by_user_id)->toBe($student->id)
        ->and($classroom->pendingStudents()->whereKey($student->id)->exists())->toBeFalse();
    Queue::assertPushed(ProvisionClassroomGroup::class, fn ($job) => $job->classroomGroupId === $group->id);
});

test('student onboarding lists manually created teams to join', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'name' => 'Open Source Lab',
        'created_manually' => true,
    ]);
    $student = User::factory()->create();

    $response = $this->actingAs($student)->get(route('classrooms.join', $classroom->join_code));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('available_groups.0.id', $group->id)
        ->where('available_groups.0.name', 'Open Source Lab')
        ->where('available_groups.0.student_count', 0));
});

test('student cannot join a canvas-managed team through manual team membership', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create(['created_manually' => false]);
    $student = User::factory()->create();

    $response = $this->actingAs($student)->post(route('student-classroom-group-memberships.store', [
        $classroom->join_code,
        $group,
    ]));

    $response->assertNotFound();
    expect($group->rosterEntries()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('teacher can disable student team creation', function () {
    $classroom = Classroom::factory()->installed()->create();

    $response = $this->actingAs($classroom->teacher)->patch(route('classroom-team-creation.update'), [
        'enabled' => false,
    ]);

    $response->assertRedirect(route('dashboard'));
    expect($classroom->fresh()->student_team_creation_enabled)->toBeFalse();
});

test('student cannot create a team when classroom setting is disabled', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create([
        'student_team_creation_enabled' => false,
    ]);
    $student = User::factory()->create();

    $response = $this->actingAs($student)->post(route('student-classroom-groups.store', $classroom->join_code), [
        'name' => 'Blocked Project',
    ]);

    $response->assertForbidden();
    expect($classroom->groups()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('later roster import preserves manually created teams', function () {
    $classroom = Classroom::factory()->installed()->create(['roster_skipped_at' => now()]);
    $manualGroup = ClassroomGroup::factory()->for($classroom)->create([
        'name' => 'Manual Project',
        'repository_name' => 'manual-project',
        'created_manually' => true,
    ]);
    $roster = UploadedFile::fake()->createWithContent('roster.csv', rosterCsv());

    $this->actingAs($classroom->teacher)->post(route('roster.store'), [
        'roster' => $roster,
        'repository_visibility' => 'private',
    ])->assertRedirect(route('dashboard'));

    expect($manualGroup->fresh())->not->toBeNull()
        ->and($classroom->groups()->where('created_manually', false)->count())->toBe(1)
        ->and($classroom->fresh()->roster_skipped_at)->toBeNull();
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

test('student can skip roster selection for teacher linking', function () {
    $classroom = Classroom::factory()->installed()->create();
    $student = User::factory()->create();

    $response = $this->actingAs($student)->post(route('pending-classroom-students.store', $classroom->join_code));

    $response->assertRedirect(route('home'));
    expect($classroom->pendingStudents()->whereKey($student->id)->exists())->toBeTrue();
});

test('pending student is prompted to select a roster entry on dashboard visits', function () {
    $classroom = Classroom::factory()->installed()->create();
    $student = User::factory()->create();
    $classroom->pendingStudents()->attach($student);

    $response = $this->actingAs($student)->get(route('dashboard'));

    $response->assertRedirect(route('classrooms.join', $classroom->join_code));
    expect($student->fresh()->classroom)->toBeNull();
});

test('teacher sees pending github students and available roster entries', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $entry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create(['name' => 'Canvas Student']);
    $student = User::factory()->create(['github_login' => 'octocat']);
    $classroom->pendingStudents()->attach($student);

    $response = $this->actingAs($classroom->teacher)->get(route('dashboard'));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('classroom.pending_students.0.github_login', 'octocat')
        ->where('classroom.unclaimed_entries.0.id', $entry->id)
        ->where('classroom.unclaimed_entries.0.group', $group->name));
});

test('teacher can link a pending github student to a roster entry', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $entry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create();
    $student = User::factory()->create();
    $classroom->pendingStudents()->attach($student);

    $response = $this->actingAs($classroom->teacher)->post(route('pending-roster-claims.store', $student), [
        'roster_entry_id' => $entry->id,
    ]);

    $response->assertRedirect(route('dashboard'));
    expect($entry->fresh()->claimed_by_user_id)->toBe($student->id)
        ->and($classroom->pendingStudents()->whereKey($student->id)->exists())->toBeFalse();
    Queue::assertPushed(ProvisionClassroomGroup::class, fn ($job) => $job->classroomGroupId === $group->id);
});

test('teacher cannot link a github student pending in another classroom', function () {
    $classroom = Classroom::factory()->installed()->create();
    $otherClassroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $entry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create();
    $student = User::factory()->create();
    $otherClassroom->pendingStudents()->attach($student);

    $response = $this->actingAs($classroom->teacher)->post(route('pending-roster-claims.store', $student), [
        'roster_entry_id' => $entry->id,
    ]);

    $response->assertNotFound();
    expect($entry->fresh()->claimed_by_user_id)->toBeNull();
});

test('student claim clears a pending teacher-link request', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $entry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create();
    $student = User::factory()->create();
    $classroom->pendingStudents()->attach($student);

    $this->actingAs($student)->post(route('roster-claims.store', [$classroom->join_code, $entry]));

    expect($classroom->pendingStudents()->whereKey($student->id)->exists())->toBeFalse();
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
    expect($classroom->pendingStudents()->whereKey($student->id)->exists())->toBeTrue();
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
