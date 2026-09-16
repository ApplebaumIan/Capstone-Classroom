<?php

namespace App\Actions;

use App\GitHubSyncIssueType;
use App\GitHubSyncResolution;
use App\Jobs\ReconcileGitHubSyncIssue;
use App\Models\ClassroomGroup;
use App\Models\GitHubSyncIssue;
use App\Models\RosterEntry;
use App\Models\User;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AcceptGitHubSyncIssue
{
    public function __construct(public GitHubAppClient $github) {}

    public function handle(GitHubSyncIssue $issue): void
    {
        if (! in_array($issue->type, [GitHubSyncIssueType::MembershipAdded, GitHubSyncIssueType::MembershipRemoved], true)) {
            abort(409, 'This GitHub change cannot be accepted.');
        }

        $issue->load('classroomGroup.classroom');
        abort_if($issue->reconciling_at !== null, 409, 'This GitHub change is already being resynchronized.');

        if ($issue->github_login === null) {
            throw ValidationException::withMessages([
                'github_sync' => 'This GitHub membership does not identify a user.',
            ]);
        }

        $isMember = $this->github->teamHasMember($issue->classroomGroup, $issue->github_login);
        $stillDrifted = match ($issue->type) {
            GitHubSyncIssueType::MembershipAdded => $isMember,
            GitHubSyncIssueType::MembershipRemoved => ! $isMember,
            default => false,
        };

        if (! $stillDrifted) {
            $this->resolveConvergedIssue($issue);

            return;
        }

        $user = User::query()->where('github_id', $issue->github_user_id)->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'github_sync' => 'This GitHub account is not linked to a Capstone Classroom user.',
            ]);
        }

        if ($issue->type === GitHubSyncIssueType::MembershipAdded) {
            $reconciliationIssueId = $this->acceptAddition($issue, $user);

            if ($reconciliationIssueId !== null) {
                ReconcileGitHubSyncIssue::dispatch($reconciliationIssueId);
            }

            return;
        }

        $this->acceptRemoval($issue, $user);
    }

    private function acceptAddition(GitHubSyncIssue $issue, User $user): ?int
    {
        return DB::transaction(function () use ($issue, $user): ?int {
            $lockedIssue = GitHubSyncIssue::query()
                ->with('classroomGroup.classroom')
                ->lockForUpdate()
                ->findOrFail($issue->id);

            if ($lockedIssue->resolved_at !== null) {
                return null;
            }

            abort_if($lockedIssue->reconciling_at !== null, 409, 'This GitHub change is already being resynchronized.');

            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            $group = $lockedIssue->classroomGroup;
            $classroom = $group->classroom;
            $existingEntry = $classroom->rosterEntries()
                ->where('claimed_by_user_id', $user->id)
                ->lockForUpdate()
                ->first();
            $isPending = $classroom->pendingStudents()->whereKey($user->id)->exists();

            if ($existingEntry === null && ! $isPending) {
                throw ValidationException::withMessages([
                    'github_sync' => 'This GitHub user is not a member of this classroom.',
                ]);
            }

            if ($existingEntry === null) {
                RosterEntry::query()->create([
                    'classroom_id' => $classroom->id,
                    'classroom_group_id' => $group->id,
                    'claimed_by_user_id' => $user->id,
                    'canvas_user_id' => "github-user-{$user->id}",
                    'canvas_login_id' => $user->github_login ?? $user->email,
                    'canvas_id' => "github-user-{$user->id}",
                    'name' => $user->name,
                    'sections' => 'Teacher-accepted GitHub membership',
                    'claimed_at' => now(),
                ]);
                $classroom->pendingStudents()->detach($user->id);
                $this->resolve($lockedIssue);

                return null;
            }

            $previousGroup = ClassroomGroup::query()->findOrFail($existingEntry->classroom_group_id);
            abort_if(
                $existingEntry->claimed_by_user_id === $classroom->teacher_id
                    && str_starts_with($existingEntry->canvas_user_id, 'github-user-')
                    && $previousGroup->id !== $group->id,
                409,
                'The classroom testing membership cannot be moved through GitHub synchronization.',
            );
            $existingEntry->update(['classroom_group_id' => $group->id]);
            $this->resolve($lockedIssue);

            if ($previousGroup->id === $group->id || $previousGroup->github_team_slug === null || $user->github_login === null) {
                return null;
            }

            return GitHubSyncIssue::query()->create([
                'classroom_group_id' => $previousGroup->id,
                'delivery_id' => "accepted-{$lockedIssue->id}",
                'type' => GitHubSyncIssueType::MembershipAdded,
                'github_user_id' => $user->github_id,
                'github_login' => $user->github_login,
                'detected_at' => now(),
            ])->id;
        });
    }

    private function acceptRemoval(GitHubSyncIssue $issue, User $user): void
    {
        DB::transaction(function () use ($issue, $user): void {
            $lockedIssue = GitHubSyncIssue::query()
                ->with('classroomGroup.classroom')
                ->lockForUpdate()
                ->findOrFail($issue->id);

            if ($lockedIssue->resolved_at !== null) {
                return;
            }

            abort_if($lockedIssue->reconciling_at !== null, 409, 'This GitHub change is already being resynchronized.');

            $group = $lockedIssue->classroomGroup;
            $entry = $group->rosterEntries()
                ->where('claimed_by_user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($entry === null) {
                $this->resolve($lockedIssue);

                return;
            }

            abort_if(
                $entry->claimed_by_user_id === $group->classroom->teacher_id
                    && str_starts_with($entry->canvas_user_id, 'github-user-'),
                409,
                'The classroom testing membership cannot be removed through GitHub synchronization.',
            );

            $entry->update([
                'claimed_by_user_id' => null,
                'claimed_at' => null,
            ]);
            $group->classroom->pendingStudents()->syncWithoutDetaching([$user->id]);
            $this->resolve($lockedIssue);
        });
    }

    private function resolve(GitHubSyncIssue $issue): void
    {
        $issue->update([
            'resolved_at' => now(),
            'resolution' => GitHubSyncResolution::Accepted,
        ]);
    }

    private function resolveConvergedIssue(GitHubSyncIssue $issue): void
    {
        DB::transaction(function () use ($issue): void {
            GitHubSyncIssue::query()
                ->whereKey($issue->id)
                ->whereNull('resolved_at')
                ->whereNull('reconciling_at')
                ->lockForUpdate()
                ->update([
                    'resolved_at' => now(),
                    'resolution' => GitHubSyncResolution::Resynced,
                ]);
        });
    }
}
