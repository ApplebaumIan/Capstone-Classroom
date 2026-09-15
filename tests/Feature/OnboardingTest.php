<?php

use App\GroupStatus;
use App\Jobs\ProvisionClassroomGroup;
use App\Jobs\RemoveStudentFromGitHubTeam;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\GitHubSyncIssue;
use App\Models\RosterEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;

uses(RefreshDatabase::class);

function rosterCsv(): string
{
    return <<<'CSV'
name,canvas_user_id,user_id,login_id,sections,group_name,canvas_group_id,group_id
"Tan, Timmy",265892,tul26954,tul26954,Section: 002,Section 002: Vulnhunter,404005,
Jane Doe,265893,tul26955,tul26955,Section: 002,Section 002: Vulnhunter,404005,
CSV;
}

test('dashboard shows empty classroom overview for a new teacher', function () {
    $teacher = User::factory()->create();

    $response = $this->actingAs($teacher)->get(route('dashboard'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('dashboard')
        ->where('mode', 'teacher')
        ->where('classrooms', []));
    expect($teacher->fresh()->classrooms)->toBeEmpty();
});

test('teacher imports quoted Canvas roster into groups', function () {
    $classroom = Classroom::factory()->installed()->create(['roster_skipped_at' => now()]);
    $roster = UploadedFile::fake()->createWithContent('roster.csv', rosterCsv());

    $response = $this->actingAs($classroom->teacher)->post(route('roster.store', $classroom), [
        'roster' => $roster,
        'repository_visibility' => 'private',
    ]);

    $response->assertRedirect(route('classrooms.students', $classroom));
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

    $response = $this->actingAs($classroom->teacher)->post(route('roster-import-skips.store', $classroom));

    $response->assertRedirect(route('classrooms.students', $classroom));
    expect($classroom->fresh()->roster_skipped_at)->not->toBeNull()
        ->and($classroom->fresh()->roster_imported_at)->toBeNull();
    $this->get(route('classrooms.students', $classroom))
        ->assertInertia(fn (Assert $page) => $page
            ->where('classroom.roster_skipped', true)
            ->where('classroom.roster_imported', false));
});

test('teacher cannot skip roster import before installing github', function () {
    $classroom = Classroom::factory()->create();

    $response = $this->actingAs($classroom->teacher)->post(route('roster-import-skips.store', $classroom));

    $response->assertConflict();
    expect($classroom->fresh()->roster_skipped_at)->toBeNull();
});

test('teacher can create a team without a roster', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create();

    $response = $this->actingAs($classroom->teacher)->post(route('classroom-groups.store', $classroom), [
        'name' => 'Project Atlas',
    ]);

    $response->assertRedirect(route('classrooms.teams', $classroom));
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
        ->and($classroom->pendingStudents()->whereKey($student->id)->exists())->toBeTrue();
    Queue::assertPushed(ProvisionClassroomGroup::class, fn ($job) => $job->classroomGroupId === $group->id);

    $this->get(route('dashboard'))->assertOk();
    $this->withSession(['onboarding.pending_dashboard_classroom_id' => null])
        ->get(route('dashboard'))
        ->assertRedirect(route('classrooms.join', $classroom->join_code));
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
        ->and($classroom->pendingStudents()->whereKey($student->id)->exists())->toBeTrue();
    Queue::assertPushed(ProvisionClassroomGroup::class, fn ($job) => $job->classroomGroupId === $group->id);
});

test('student onboarding lists manually created teams to join', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create([
        'name' => 'Open Source Lab',
        'created_manually' => true,
    ]);
    $student = User::factory()->create();

    $response = $this->actingAs($student)
        ->withSession(['onboarding.team_selection_classroom_id' => $classroom->id])
        ->get(route('classrooms.join', $classroom->join_code));

    $response->assertInertia(fn (Assert $page) => $page
        ->where('available_groups.0.id', $group->id)
        ->where('available_groups.0.name', 'Open Source Lab')
        ->where('available_groups.0.student_count', 0));
});

test('student cannot join a team from another classroom', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create();
    $otherClassroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($otherClassroom)->create();
    $student = User::factory()->create();
    $classroom->pendingStudents()->attach($student);

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

    $response = $this->actingAs($classroom->teacher)->patch(route('classroom-team-creation.update', $classroom), [
        'enabled' => false,
    ]);

    $response->assertRedirect(route('classrooms.teams', $classroom));
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

    $this->actingAs($classroom->teacher)->post(route('roster.store', $classroom), [
        'roster' => $roster,
        'repository_visibility' => 'private',
    ])->assertRedirect(route('classrooms.students', $classroom));

    expect($manualGroup->fresh())->not->toBeNull()
        ->and($classroom->groups()->where('created_manually', false)->count())->toBe(1)
        ->and($classroom->fresh()->roster_skipped_at)->toBeNull();
});

test('roster import rejects unexpected headers', function () {
    $classroom = Classroom::factory()->installed()->create();
    $roster = UploadedFile::fake()->createWithContent('roster.csv', "name,group\nTimmy,Vulnhunter");

    $response = $this->actingAs($classroom->teacher)->post(route('roster.store', $classroom), [
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

    $response->assertRedirect(route('classrooms.join', $classroom->join_code));
    expect($classroom->pendingStudents()->whereKey($student->id)->exists())->toBeTrue();
    $this->get(route('classrooms.join', $classroom->join_code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selecting_team', true)
            ->where('entries', []));
});

test('pending student is prompted to select a roster entry on dashboard visits', function () {
    $classroom = Classroom::factory()->installed()->create();
    $student = User::factory()->create();
    $classroom->pendingStudents()->attach($student);

    $response = $this->actingAs($student)->get(route('dashboard'));

    $response->assertRedirect(route('classrooms.join', $classroom->join_code));
    expect($student->fresh()->classrooms)->toBeEmpty();
});

test('teacher sees pending github students and available roster entries', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $entry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create(['name' => 'Canvas Student']);
    $student = User::factory()->create(['github_login' => 'octocat']);
    $classroom->pendingStudents()->attach($student);

    $response = $this->actingAs($classroom->teacher)->get(route('classrooms.students', $classroom));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('classrooms/students')
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

    $response = $this->actingAs($classroom->teacher)->post(route('pending-roster-claims.store', [$classroom, $student]), [
        'roster_entry_id' => $entry->id,
    ]);

    $response->assertRedirect(route('classrooms.students', $classroom));
    expect($entry->fresh()->claimed_by_user_id)->toBe($student->id)
        ->and($classroom->pendingStudents()->whereKey($student->id)->exists())->toBeFalse();
    Queue::assertPushed(ProvisionClassroomGroup::class, fn ($job) => $job->classroomGroupId === $group->id);
});

test('canvas roster team replaces the temporary team selected by a skipped student', function () {
    Queue::fake([ProvisionClassroomGroup::class, RemoveStudentFromGitHubTeam::class]);
    $classroom = Classroom::factory()->installed()->create();
    $temporaryGroup = ClassroomGroup::factory()->for($classroom)->create([
        'created_manually' => true,
        'github_team_slug' => 'temporary-team',
    ]);
    $canvasGroup = ClassroomGroup::factory()->for($classroom)->create();
    $student = User::factory()->create(['github_login' => 'octocat']);
    $temporaryEntry = RosterEntry::factory()->for($classroom)->for($temporaryGroup, 'group')->create([
        'canvas_user_id' => "github-user-{$student->id}",
        'claimed_by_user_id' => $student->id,
        'claimed_at' => now(),
    ]);
    $canvasEntry = RosterEntry::factory()->for($classroom)->for($canvasGroup, 'group')->create();
    $classroom->pendingStudents()->attach($student);

    $this->actingAs($classroom->teacher)->post(route('pending-roster-claims.store', [$classroom, $student]), [
        'roster_entry_id' => $canvasEntry->id,
    ])->assertRedirect(route('classrooms.students', $classroom));

    $this->assertModelMissing($temporaryEntry);
    expect($canvasEntry->fresh()->claimed_by_user_id)->toBe($student->id)
        ->and($classroom->pendingStudents()->whereKey($student->id)->exists())->toBeFalse();
    Queue::assertPushed(RemoveStudentFromGitHubTeam::class, fn ($job) => $job->classroomGroupId === $temporaryGroup->id && $job->githubLogin === 'octocat');
    Queue::assertPushed(ProvisionClassroomGroup::class, fn ($job) => $job->classroomGroupId === $canvasGroup->id);
});

test('teacher cannot link a github student pending in another classroom', function () {
    $classroom = Classroom::factory()->installed()->create();
    $otherClassroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $entry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create();
    $student = User::factory()->create();
    $otherClassroom->pendingStudents()->attach($student);

    $response = $this->actingAs($classroom->teacher)->post(route('pending-roster-claims.store', [$classroom, $student]), [
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

    $response = $this->actingAs($classroom->teacher)->delete(route('roster-claims.destroy', [$classroom, $entry]));

    $response->assertRedirect(route('classrooms.students', $classroom));
    expect($entry->fresh()->claimed_by_user_id)->toBeNull();
    expect($classroom->pendingStudents()->whereKey($student->id)->exists())->toBeTrue();
    Queue::assertPushed(RemoveStudentFromGitHubTeam::class, fn ($job) => $job->githubLogin === 'octocat');
});

test('another teacher cannot reset a roster claim', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $entry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create();
    $otherTeacher = User::factory()->create();

    $response = $this->actingAs($otherTeacher)->delete(route('roster-claims.destroy', [$classroom, $entry]));

    $response->assertNotFound();
});

test('teacher dashboard lists multiple classrooms and sidebar navigation', function () {
    $teacher = User::factory()->create();
    $firstClassroom = Classroom::factory()->installed()->for($teacher, 'teacher')->create(['name' => 'Classroom A']);
    $secondClassroom = Classroom::factory()->installed()->for($teacher, 'teacher')->create(['name' => 'Classroom B']);

    $this->actingAs($teacher)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'teacher')
            ->has('classrooms', 2)
            ->where('classrooms.0.name', 'Classroom A')
            ->where('classrooms.1.name', 'Classroom B')
            ->where('teacherNavigation.classrooms.0.id', $firstClassroom->id)
            ->where('teacherNavigation.classrooms.1.id', $secondClassroom->id)
            ->where('teacherNavigation.can_create_classroom', true));
});

test('teacher classroom pages are private to their owner', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $otherTeacher = User::factory()->create();

    $this->actingAs($otherTeacher)
        ->get(route('classrooms.students', $classroom))
        ->assertNotFound();
    $this->get(route('classrooms.teams', $classroom))->assertNotFound();
    $this->post(route('classroom-groups.store', $classroom), [
        'name' => 'Unauthorized Team',
    ])->assertNotFound();
    $this->patch(route('classroom-team-creation.update', $classroom), [
        'enabled' => false,
    ])->assertNotFound();
    $this->post(route('roster.store', $classroom))->assertNotFound();
    $this->post(route('group-provisioning.store', [$classroom, $group]))->assertNotFound();
    $this->put(route('classrooms.update', $classroom), [])->assertNotFound();
});

test('teacher deletes classroom data after confirming its exact name', function () {
    $classroom = Classroom::factory()->installed()->create(['name' => 'CIS 4398 Fall']);
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $student = User::factory()->create();
    RosterEntry::factory()->for($classroom)->for($group, 'group')->create([
        'claimed_by_user_id' => $student->id,
    ]);
    $classroom->pendingStudents()->attach(User::factory()->create());
    GitHubSyncIssue::factory()->for($group, 'classroomGroup')->create();

    $this->actingAs($classroom->teacher)
        ->delete(route('classrooms.destroy', $classroom), [
            'confirmation' => 'CIS 4398 Fall',
        ])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'Classroom deleted. GitHub repositories and teams were preserved.');

    $this->assertDatabaseMissing('classrooms', ['id' => $classroom->id]);
    $this->assertDatabaseMissing('classroom_groups', ['id' => $group->id]);
    $this->assertDatabaseMissing('roster_entries', ['classroom_id' => $classroom->id]);
    $this->assertDatabaseMissing('pending_classroom_students', ['classroom_id' => $classroom->id]);
    $this->assertDatabaseMissing('github_sync_issues', ['classroom_group_id' => $group->id]);
    $this->assertDatabaseHas('users', ['id' => $student->id]);
});

test('teacher must confirm exact classroom name before deletion', function () {
    $classroom = Classroom::factory()->create(['name' => 'CIS 4398 Fall']);

    $this->actingAs($classroom->teacher)
        ->from(route('dashboard'))
        ->delete(route('classrooms.destroy', $classroom), [
            'confirmation' => 'cis 4398 fall',
        ])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors([
            'confirmation' => 'Enter the classroom name exactly to confirm deletion.',
        ]);

    $this->assertDatabaseHas('classrooms', ['id' => $classroom->id]);
});

test('another teacher cannot delete classroom', function () {
    $classroom = Classroom::factory()->create();

    $this->actingAs(User::factory()->create())
        ->delete(route('classrooms.destroy', $classroom), [
            'confirmation' => $classroom->name,
        ])
        ->assertNotFound();

    $this->assertDatabaseHas('classrooms', ['id' => $classroom->id]);
});

test('classroom owner sees teacher testing mode instead of canvas identities', function () {
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create();
    RosterEntry::factory()->for($classroom)->for($group, 'group')->create();

    $this->actingAs($classroom->teacher)
        ->get(route('classrooms.join', $classroom->join_code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('teacher_testing', true)
            ->where('testing_group', null)
            ->where('entries', []));
});

test('classroom owner creates a testing team without becoming an unlinked student', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create();
    $teacher = $classroom->teacher;

    $this->actingAs($teacher)->post(route('student-classroom-groups.store', $classroom->join_code), [
        'name' => 'Teacher Test Team',
    ])->assertRedirect(route('classrooms.join', $classroom->join_code));

    $group = $classroom->groups()->firstOrFail();
    $entry = $group->rosterEntries()->firstOrFail();
    expect($entry->claimed_by_user_id)->toBe($teacher->id)
        ->and($entry->sections)->toBe('Teacher testing team')
        ->and($classroom->pendingStudents()->whereKey($teacher->id)->exists())->toBeFalse();
    Queue::assertPushed(ProvisionClassroomGroup::class, fn ($job) => $job->classroomGroupId === $group->id);

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('mode', 'teacher'));
    $this->get(route('classrooms.students', $classroom))
        ->assertInertia(fn (Assert $page) => $page
            ->where('classroom.students', [])
            ->where('classroom.pending_students', []));
    $this->get(route('classrooms.teams', $classroom))
        ->assertInertia(fn (Assert $page) => $page
            ->where('classroom.groups.0.is_testing', true)
            ->where('classroom.groups.0.students.0.id', $entry->id));
    $this->delete(route('roster-claims.destroy', [$classroom, $entry]))->assertNotFound();

    $roster = UploadedFile::fake()->createWithContent('roster.csv', rosterCsv());
    $this->post(route('roster.store', $classroom), [
        'roster' => $roster,
        'repository_visibility' => 'private',
    ])->assertRedirect(route('classrooms.students', $classroom));
    expect($group->fresh())->not->toBeNull()
        ->and($classroom->rosterEntries()->where('canvas_user_id', 'not like', 'github-user-%')->count())->toBe(2);
});

test('classroom owner cannot create a testing team when student creation is disabled', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create([
        'student_team_creation_enabled' => false,
    ]);

    $this->actingAs($classroom->teacher)
        ->post(route('student-classroom-groups.store', $classroom->join_code), [
            'name' => 'Blocked Test Team',
        ])
        ->assertForbidden();

    expect($classroom->groups()->exists())->toBeFalse();
    Queue::assertNothingPushed();
});

test('classroom owner cannot claim a canvas student identity', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $classroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($classroom)->create();
    $entry = RosterEntry::factory()->for($classroom)->for($group, 'group')->create();

    $this->actingAs($classroom->teacher)
        ->post(route('roster-claims.store', [$classroom->join_code, $entry]))
        ->assertForbidden();

    expect($entry->fresh()->claimed_by_user_id)->toBeNull();
    Queue::assertNothingPushed();
});

test('teacher can join another classroom without losing teacher dashboard', function () {
    Queue::fake([ProvisionClassroomGroup::class]);
    $teacher = User::factory()->create();
    Classroom::factory()->installed()->for($teacher, 'teacher')->create();
    $studentClassroom = Classroom::factory()->installed()->create();
    $group = ClassroomGroup::factory()->for($studentClassroom)->create();
    $entry = RosterEntry::factory()->for($studentClassroom)->for($group, 'group')->create();

    $this->actingAs($teacher)
        ->post(route('roster-claims.store', [$studentClassroom->join_code, $entry]))
        ->assertRedirect(route('classrooms.join', $studentClassroom->join_code));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('mode', 'teacher'));
    $this->get(route('classrooms.join', $studentClassroom->join_code))
        ->assertInertia(fn (Assert $page) => $page
            ->where('teacher_testing', false)
            ->where('claim.name', $entry->name));
});

test('github organization can belong to only one classroom', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/user/installations*' => Http::response([
            'installations' => [[
                'id' => 999,
                'target_type' => 'Organization',
                'account' => ['id' => 456, 'login' => 'temple'],
            ]],
        ]),
    ]);
    Classroom::factory()->create([
        'github_installation_id' => '123',
        'github_organization_id' => '456',
        'github_organization_login' => 'temple',
    ]);
    $teacher = User::factory()->create();

    $this->actingAs($teacher)
        ->withSession(['github.user_access_token' => Crypt::encryptString('user-token')])
        ->post(route('classrooms.store'), [
            'name' => 'Duplicate Classroom',
            'installation_id' => '999',
        ])
        ->assertSessionHasErrors([
            'installation_id' => 'That GitHub organization already has a classroom.',
        ]);

    expect($teacher->classrooms()->exists())->toBeFalse();
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
    $teacher = User::factory()->create();

    $response = $this->actingAs($teacher)
        ->withSession(['github.user_access_token' => Crypt::encryptString('user-token')])
        ->post(route('classrooms.store'), [
            'name' => 'Classroom A',
            'installation_id' => '123',
        ]);

    $classroom = $teacher->classrooms()->firstOrFail();
    $response->assertRedirect(route('classrooms.students', $classroom));
    expect($classroom->fresh())
        ->name->toBe('Classroom A')
        ->github_installation_id->toBe('123')
        ->github_organization_login->toBe('temple');
});

test('teacher cannot connect a spoofed GitHub installation', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/user/installations*' => Http::response(['installations' => []]),
    ]);
    $teacher = User::factory()->create();

    $response = $this->actingAs($teacher)
        ->withSession(['github.user_access_token' => Crypt::encryptString('user-token')])
        ->post(route('classrooms.store'), [
            'name' => 'Classroom A',
            'installation_id' => '999',
        ]);

    $response->assertSessionHasErrors([
        'installation_id' => 'That GitHub App installation is not available to your account.',
    ]);
    expect($teacher->classrooms()->exists())->toBeFalse();
});

test('teacher starts github organization setup with oauth', function () {
    $teacher = User::factory()->create();
    $provider = Mockery::mock(GithubProvider::class);
    $provider->shouldReceive('redirectUrl')->once()->andReturnSelf();
    $provider->shouldReceive('redirect')
        ->once()
        ->andReturn(redirect()->away('https://github.com/login/oauth/authorize'));
    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

    $response = $this->actingAs($teacher)->get(route('github.installations.create'));

    $response->assertRedirect('https://github.com/login/oauth/authorize')
        ->assertSessionHas('github.installation_pending', true);
});

test('teacher opens github app installation separately', function () {
    config(['services.github.app_slug' => 'capstone-preview']);
    $teacher = User::factory()->create();

    $response = $this->actingAs($teacher)->get(route('github.installations.edit'));

    $response->assertRedirect('https://github.com/apps/capstone-preview/installations/new');
});

test('teacher refreshes github organizations after installation', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/user/installations*' => Http::response([
            'installations' => [[
                'id' => 123,
                'target_type' => 'Organization',
                'account' => [
                    'id' => 456,
                    'login' => 'temple',
                    'avatar_url' => 'https://avatars.example.com/temple',
                ],
            ]],
        ]),
    ]);
    $classroom = Classroom::factory()->create([
        'github_installation_id' => null,
        'github_organization_id' => null,
        'github_organization_login' => null,
    ]);

    $response = $this->actingAs($classroom->teacher)
        ->withSession([
            'github.installation_pending' => true,
            'github.installation_classroom_id' => $classroom->id,
            'github.user_access_token' => Crypt::encryptString('user-token'),
        ])
        ->post(route('github.installations.store'));

    $response->assertRedirect(route('classrooms.edit', $classroom, absolute: false))
        ->assertSessionHas('github.available_installations', [[
            'id' => '123',
            'account_id' => '456',
            'login' => 'temple',
            'avatar_url' => 'https://avatars.example.com/temple',
        ]]);
});

test('teacher must reconnect github before refreshing organizations', function () {
    $teacher = User::factory()->create();

    $response = $this->actingAs($teacher)
        ->post(route('github.installations.store'));

    $response->assertSessionHasErrors([
        'github' => 'Reconnect GitHub before refreshing organizations.',
    ]);
});

test('student cannot start github organization setup', function () {
    $classroom = Classroom::factory()->installed()->create();
    $student = User::factory()->create();
    $classroom->pendingStudents()->attach($student);

    $this->actingAs($student)
        ->get(route('github.installations.create'))
        ->assertForbidden();
});
