<?php

namespace App\Actions;

use App\GroupStatus;
use App\Jobs\ProvisionClassroomGroup;
use App\Jobs\RemoveStudentFromGitHubTeam;
use App\Models\Classroom;
use App\Models\RosterEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClaimRosterEntry
{
    public function handle(User $user, Classroom $classroom, RosterEntry $rosterEntry): RosterEntry
    {
        $previousGroupId = null;
        $previousTeamSlug = null;

        $entry = DB::transaction(function () use (
            $user,
            $classroom,
            $rosterEntry,
            &$previousGroupId,
            &$previousTeamSlug,
        ): RosterEntry {
            $lockedEntry = RosterEntry::query()->lockForUpdate()->findOrFail($rosterEntry->id);

            if ($lockedEntry->classroom_id !== $classroom->id) {
                abort(404);
            }

            if ($lockedEntry->claimed_by_user_id !== null) {
                throw ValidationException::withMessages(['roster_entry' => 'That roster entry has already been claimed.']);
            }

            $existingClaim = $classroom->rosterEntries()
                ->with('group')
                ->where('claimed_by_user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existingClaim !== null && ! str_starts_with($existingClaim->canvas_user_id, 'github-user-')) {
                throw ValidationException::withMessages(['roster_entry' => 'You have already claimed a roster entry.']);
            }

            if ($existingClaim !== null) {
                $previousGroupId = $existingClaim->classroom_group_id;
                $previousTeamSlug = $existingClaim->group->github_team_slug;
                $existingClaim->delete();
            }

            $lockedEntry->update([
                'claimed_by_user_id' => $user->id,
                'claimed_at' => now(),
            ]);
            $classroom->pendingStudents()->detach($user->id);

            return $lockedEntry;
        });

        if (
            $previousGroupId !== null
            && $previousGroupId !== $entry->classroom_group_id
            && $previousTeamSlug !== null
            && $user->github_login !== null
        ) {
            RemoveStudentFromGitHubTeam::dispatch($previousGroupId, $user->github_login);
        }

        $entry->load('group');

        if ($entry->group->status !== GroupStatus::Ready) {
            $entry->group->update(['status' => GroupStatus::Provisioning, 'provisioning_error' => null]);
        }

        ProvisionClassroomGroup::dispatch($entry->classroom_group_id);

        return $entry;
    }
}
