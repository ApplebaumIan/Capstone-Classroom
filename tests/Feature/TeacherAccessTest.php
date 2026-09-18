<?php

use App\Mail\TeacherAccessApproved;
use App\Mail\TeacherAccessRequested;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

test('classroom creation policy requires approved non-student access', function () {
    $approvedTeacher = User::factory()->create();
    $unapprovedTeacher = User::factory()->withoutTeacherAccess()->create();
    $student = User::factory()->create();
    $classroom = Classroom::factory()->create();
    $classroom->pendingStudents()->attach($student);

    expect(Gate::forUser($approvedTeacher)->allows('create', Classroom::class))->toBeTrue()
        ->and(Gate::forUser($unapprovedTeacher)->allows('create', Classroom::class))->toBeFalse()
        ->and(Gate::forUser($student)->allows('create', Classroom::class))->toBeFalse();
});

test('new teachers can request access once', function () {
    Mail::fake();
    config(['services.teacher_access.approver_email' => 'owner@example.com']);
    $teacher = User::factory()->withoutTeacherAccess()->create();

    $this->actingAs($teacher)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('mode', 'teacher_access')
            ->where('teacher_access_requested', false)
            ->where('teacherNavigation.can_create_classroom', false));

    $this->post(route('teacher-access.requests.store'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('success', 'Your teacher access request is awaiting approval.');

    expect($teacher->fresh()->teacher_access_requested_at)->not->toBeNull();
    Mail::assertSent(TeacherAccessRequested::class, function (TeacherAccessRequested $mail) use ($teacher): bool {
        return $mail->hasTo('owner@example.com')
            && $mail->user->is($teacher)
            && str_contains($mail->approvalUrl, '/teacher-access/approvals/'.$teacher->id);
    });

    Mail::fake();
    $this->post(route('teacher-access.requests.store'))->assertRedirect(route('dashboard'));
    Mail::assertNothingOutgoing();
});

test('teacher access requests require an approver email', function () {
    config(['services.teacher_access.approver_email' => null]);
    $teacher = User::factory()->withoutTeacherAccess()->create();

    $this->actingAs($teacher)
        ->post(route('teacher-access.requests.store'))
        ->assertServiceUnavailable();

    expect($teacher->fresh()->teacher_access_requested_at)->toBeNull();
});

test('students cannot request teacher access', function () {
    $classroom = Classroom::factory()->create();
    $student = User::factory()->withoutTeacherAccess()->create();
    $classroom->pendingStudents()->attach($student);

    $this->actingAs($student)
        ->post(route('teacher-access.requests.store'))
        ->assertForbidden();
});

test('unapproved teachers cannot use classroom creation or github setup', function () {
    $teacher = User::factory()->withoutTeacherAccess()->create();

    $this->actingAs($teacher)
        ->get(route('classrooms.create'))
        ->assertForbidden();
    $this->post(route('classrooms.store'))->assertForbidden();
    $this->get(route('github.installations.create'))->assertForbidden();
});

test('signed approval grants access and emails the teacher', function () {
    Mail::fake();
    $teacher = User::factory()->withoutTeacherAccess()->create([
        'teacher_access_requested_at' => now(),
    ]);
    $approvalUrl = URL::signedRoute('teacher-access.approvals.show', ['user' => $teacher]);

    $this->get(route('teacher-access.approvals.show', $teacher))->assertForbidden();
    $this->get($approvalUrl)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/teacher-access-approval')
            ->where('teacher.name', $teacher->name)
            ->where('teacher.email', $teacher->email)
            ->where('approved', false)
            ->where('approval_url', $approvalUrl));

    $this->post($approvalUrl)->assertRedirect();

    $teacher->refresh();
    expect($teacher->teacher_access_approved_at)->not->toBeNull();
    Mail::assertSent(TeacherAccessApproved::class, fn (TeacherAccessApproved $mail): bool => $mail->hasTo($teacher->email));

    $this->actingAs($teacher)
        ->get(route('classrooms.create'))
        ->assertOk();
});

test('approving an approved request does not send another email', function () {
    Mail::fake();
    $teacher = User::factory()->create([
        'teacher_access_requested_at' => now(),
    ]);
    $approvalUrl = URL::signedRoute('teacher-access.approvals.show', ['user' => $teacher]);

    $this->post($approvalUrl)->assertRedirect();

    Mail::assertNothingOutgoing();
});

test('teacher access emails contain their actions', function () {
    $teacher = User::factory()->create();
    $requested = new TeacherAccessRequested($teacher, 'https://example.com/approve');
    $approved = new TeacherAccessApproved($teacher);

    $requested->assertHasSubject('Teacher access requested by '.$teacher->name)
        ->assertSeeInHtml($teacher->email)
        ->assertSeeInHtml('https://example.com/approve');
    $approved->assertHasSubject('Your teacher access is approved')
        ->assertSeeInHtml('Create a classroom');
});
