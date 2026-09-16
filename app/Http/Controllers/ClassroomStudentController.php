<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassroomStudentController extends Controller
{
    public function __invoke(Request $request, Classroom $classroom): Response
    {
        abort_unless($classroom->teacher_id === $request->user()->id, 404);

        $rosterEntries = $classroom->rosterEntries()
            ->where('canvas_user_id', 'not like', 'github-user-%')
            ->with(['group', 'claimedBy'])
            ->orderBy('name')
            ->get();

        return Inertia::render('classrooms/students', [
            'classroom' => [
                'id' => $classroom->id,
                'name' => $classroom->name,
                'organization' => $classroom->github_organization_login,
                'join_url' => route('classrooms.join', $classroom->join_code),
                'installed' => $classroom->hasActiveGitHubInstallation(),
                'roster_imported' => $classroom->roster_imported_at !== null,
                'roster_skipped' => $classroom->roster_skipped_at !== null,
                'repository_visibility' => $classroom->repository_visibility->value,
                'students' => $rosterEntries->map(fn ($entry): array => [
                    'id' => $entry->id,
                    'name' => $entry->name,
                    'sections' => $entry->sections,
                    'group' => $entry->group->name,
                    'github_login' => $entry->claimedBy?->github_login,
                    'avatar_url' => $entry->claimedBy?->avatar_url,
                    'claimed' => $entry->claimed_by_user_id !== null,
                ]),
                'pending_students' => $classroom->pendingStudents()
                    ->whereKeyNot($classroom->teacher_id)
                    ->orderBy('github_login')
                    ->get()
                    ->map(fn ($student): array => [
                        'id' => $student->id,
                        'name' => $student->name,
                        'github_login' => $student->github_login,
                        'avatar_url' => $student->avatar_url,
                    ]),
                'unclaimed_entries' => $rosterEntries
                    ->whereNull('claimed_by_user_id')
                    ->map(fn ($entry): array => [
                        'id' => $entry->id,
                        'name' => $entry->name,
                        'group' => $entry->group->name,
                    ])->values(),
            ],
        ]);
    }
}
