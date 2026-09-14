<?php

namespace App\Http\Controllers;

use App\Models\ClassroomGroup;
use App\RepositoryVisibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $claim = $user->rosterClaims()
            ->with(['classroom', 'group'])
            ->first();

        if ($claim !== null) {
            return Inertia::render('dashboard', [
                'mode' => 'student',
                'claim' => [
                    'name' => $claim->name,
                    'sections' => $claim->sections,
                    'classroom' => $claim->classroom->name,
                    'join_url' => route('classrooms.join', $claim->classroom->join_code),
                    'group' => $this->groupData($claim->group),
                ],
            ]);
        }

        $pendingClassroom = $user->pendingClassrooms()->first();

        if ($pendingClassroom !== null) {
            return to_route('classrooms.join', $pendingClassroom->join_code);
        }

        $classroom = $user->classroom()->firstOrCreate([], [
            'name' => 'CIS 4398 Capstone',
            'join_code' => Str::lower(Str::random(32)),
            'repository_visibility' => RepositoryVisibility::Private,
        ]);

        $classroom->load([
            'groups' => fn ($query) => $query->with(['rosterEntries.claimedBy'])->orderBy('name'),
        ]);

        return Inertia::render('dashboard', [
            'mode' => 'teacher',
            'classroom' => [
                'name' => $classroom->name,
                'join_url' => route('classrooms.join', $classroom->join_code),
                'organization' => $classroom->github_organization_login,
                'installed' => $classroom->github_installation_id !== null,
                'roster_imported' => $classroom->roster_imported_at !== null,
                'roster_skipped' => $classroom->roster_skipped_at !== null,
                'student_team_creation_enabled' => $classroom->student_team_creation_enabled,
                'repository_visibility' => $classroom->repository_visibility->value,
                'student_count' => $classroom->rosterEntries()->count(),
                'claimed_count' => $classroom->rosterEntries()->whereNotNull('claimed_by_user_id')->count(),
                'groups' => $classroom->groups->map(fn ($group): array => [
                    ...$this->groupData($group),
                    'students' => $group->rosterEntries->map(fn ($entry): array => [
                        'id' => $entry->id,
                        'name' => $entry->name,
                        'sections' => $entry->sections,
                        'github_login' => $entry->claimedBy?->github_login,
                        'claimed' => $entry->claimed_by_user_id !== null,
                    ]),
                ]),
                'pending_students' => $classroom->pendingStudents()
                    ->orderBy('github_login')
                    ->get()
                    ->map(fn ($student): array => [
                        'id' => $student->id,
                        'name' => $student->name,
                        'github_login' => $student->github_login,
                    ]),
                'unclaimed_entries' => $classroom->groups->flatMap(fn ($group) => $group->rosterEntries
                    ->whereNull('claimed_by_user_id')
                    ->map(fn ($entry): array => [
                        'id' => $entry->id,
                        'name' => $entry->name,
                        'group' => $group->name,
                    ]))->values(),
            ],
            'available_installations' => session('github.available_installations', []),
        ]);
    }

    /** @return array<string, mixed> */
    private function groupData(ClassroomGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'status' => $group->status->value,
            'error' => $group->provisioning_error,
            'team_url' => $group->github_team_url,
            'repository_url' => $group->github_repository_url,
            'pages_url' => $group->github_pages_url,
        ];
    }
}
