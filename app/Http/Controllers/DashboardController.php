<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use App\Models\ClassroomGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $classrooms = $user->classrooms()
            ->withCount([
                'groups',
                'rosterEntries as student_count' => fn ($query) => $query
                    ->where('canvas_user_id', 'not like', 'github-user-%'),
                'rosterEntries as claimed_students_count' => fn ($query) => $query
                    ->whereNotNull('claimed_by_user_id')
                    ->where('canvas_user_id', 'not like', 'github-user-%'),
            ])
            ->orderBy('name')
            ->get();

        if ($classrooms->isNotEmpty()) {
            return Inertia::render('dashboard', [
                'mode' => 'teacher',
                'classrooms' => $classrooms->map(fn (Classroom $classroom): array => [
                    'id' => $classroom->id,
                    'name' => $classroom->name,
                    'organization' => $classroom->github_organization_login,
                    'installed' => $classroom->hasActiveGitHubInstallation(),
                    'student_count' => $classroom->student_count,
                    'claimed_count' => $classroom->claimed_students_count,
                    'team_count' => $classroom->groups_count,
                ]),
            ]);
        }

        $pendingClassroom = $user->pendingClassrooms()->first();

        if (
            $pendingClassroom !== null
            && $request->session()->get('onboarding.pending_dashboard_classroom_id') !== $pendingClassroom->id
        ) {
            return to_route('classrooms.join', $pendingClassroom->join_code);
        }

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

        if ($pendingClassroom !== null) {
            return to_route('classrooms.join', $pendingClassroom->join_code);
        }

        return Inertia::render('dashboard', [
            'mode' => 'teacher',
            'classrooms' => [],
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
