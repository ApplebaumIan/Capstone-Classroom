<?php

namespace App\Actions;

use App\GroupStatus;
use App\Jobs\ProvisionClassroomGroup;
use App\Models\Classroom;
use App\Models\RosterEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClaimRosterEntry
{
    public function handle(User $user, Classroom $classroom, RosterEntry $rosterEntry): RosterEntry
    {
        $entry = DB::transaction(function () use ($user, $classroom, $rosterEntry): RosterEntry {
            $lockedEntry = RosterEntry::query()->lockForUpdate()->findOrFail($rosterEntry->id);

            if ($lockedEntry->classroom_id !== $classroom->id) {
                abort(404);
            }

            if ($lockedEntry->claimed_by_user_id !== null) {
                throw ValidationException::withMessages(['roster_entry' => 'That roster entry has already been claimed.']);
            }

            if ($classroom->rosterEntries()->where('claimed_by_user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['roster_entry' => 'You have already claimed a roster entry.']);
            }

            $lockedEntry->update([
                'claimed_by_user_id' => $user->id,
                'claimed_at' => now(),
            ]);

            return $lockedEntry;
        });

        $entry->load('group');

        if ($entry->group->status !== GroupStatus::Ready) {
            $entry->group->update(['status' => GroupStatus::Provisioning, 'provisioning_error' => null]);
        }

        ProvisionClassroomGroup::dispatch($entry->classroom_group_id);

        return $entry;
    }
}
