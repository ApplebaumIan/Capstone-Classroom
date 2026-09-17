<?php

namespace App\Http\Controllers;

use App\Enums\GitHubSyncIssueType;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\GitHubSyncIssue;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassroomTeamController extends Controller
{
    public function __invoke(Request $request, Classroom $classroom): Response
    {
        abort_unless($classroom->teacher_id === $request->user()->id, 404);
        $classroom->load([
            'groups' => fn ($query) => $query->with([
                'rosterEntries.claimedBy',
                'syncIssues' => fn ($query) => $query->whereNull('resolved_at')->oldest('detected_at'),
            ])->orderBy('name'),
            'pendingStudents',
        ]);

        $classroomUsersByGitHubId = $classroom->groups
            ->flatMap(fn ($group) => $group->rosterEntries->pluck('claimedBy'))
            ->merge($classroom->pendingStudents)
            ->filter(fn ($user): bool => $user?->github_id !== null)
            ->keyBy(fn ($user): string => $user->github_id);

        return Inertia::render('classrooms/teams', [
            'classroom' => [
                'id' => $classroom->id,
                'name' => $classroom->name,
                'organization' => $classroom->github_organization_login,
                'installed' => $classroom->hasActiveGitHubInstallation(),
                'installation_status' => $classroom->github_installation_status?->value,
                'student_team_creation_enabled' => $classroom->student_team_creation_enabled,
                'groups' => $classroom->groups->map(fn (ClassroomGroup $group): array => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'status' => $group->status->value,
                    'error' => $group->provisioning_error,
                    'team_name' => $group->github_team_name,
                    'team_url' => $group->github_team_url,
                    'team_missing' => $group->github_team_missing_at !== null,
                    'team_repository_access' => $group->github_team_repository_access,
                    'repository_url' => $group->github_repository_url,
                    'repository_missing' => $group->github_repository_missing_at !== null,
                    'pages_url' => $group->github_pages_url,
                    'sync_issues' => $group->syncIssues
                        ->whereIn('type', [GitHubSyncIssueType::MembershipAdded, GitHubSyncIssueType::MembershipRemoved])
                        ->map(function (GitHubSyncIssue $issue) use ($classroomUsersByGitHubId): array {
                            $user = $classroomUsersByGitHubId->get($issue->github_user_id);

                            return [
                                'id' => $issue->id,
                                'type' => $issue->type->value,
                                'github_login' => $issue->github_login,
                                'can_accept' => $user !== null,
                                'error' => $issue->metadata['sync_error'] ?? null,
                            ];
                        })->values(),
                    'is_testing' => $group->rosterEntries->contains(
                        fn ($entry): bool => $entry->claimed_by_user_id === $classroom->teacher_id
                            && str_starts_with($entry->canvas_user_id, 'github-user-'),
                    ),
                    'students' => $group->rosterEntries->map(fn ($entry): array => [
                        'id' => $entry->id,
                        'name' => $entry->name,
                        'github_login' => $entry->claimedBy?->github_login,
                        'avatar_url' => $entry->claimedBy?->avatar_url,
                        'claimed' => $entry->claimed_by_user_id !== null,
                    ]),
                ]),
            ],
        ]);
    }
}
