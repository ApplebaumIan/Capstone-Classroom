<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassroomTeamController extends Controller
{
    public function __invoke(Request $request, Classroom $classroom): Response
    {
        abort_unless($classroom->teacher_id === $request->user()->id, 404);
        $classroom->load([
            'groups' => fn ($query) => $query->with(['rosterEntries.claimedBy'])->orderBy('name'),
        ]);

        return Inertia::render('classrooms/teams', [
            'classroom' => [
                'id' => $classroom->id,
                'name' => $classroom->name,
                'organization' => $classroom->github_organization_login,
                'installed' => $classroom->github_installation_id !== null,
                'student_team_creation_enabled' => $classroom->student_team_creation_enabled,
                'groups' => $classroom->groups->map(fn ($group): array => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'status' => $group->status->value,
                    'error' => $group->provisioning_error,
                    'team_url' => $group->github_team_url,
                    'repository_url' => $group->github_repository_url,
                    'pages_url' => $group->github_pages_url,
                    'is_testing' => $group->rosterEntries->contains(
                        fn ($entry): bool => $entry->claimed_by_user_id === $classroom->teacher_id
                            && str_starts_with($entry->canvas_user_id, 'github-user-'),
                    ),
                    'students' => $group->rosterEntries->map(fn ($entry): array => [
                        'id' => $entry->id,
                        'name' => $entry->name,
                        'github_login' => $entry->claimedBy?->github_login,
                        'claimed' => $entry->claimed_by_user_id !== null,
                    ]),
                ]),
            ],
        ]);
    }
}
