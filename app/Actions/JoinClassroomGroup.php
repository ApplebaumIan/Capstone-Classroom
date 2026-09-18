<?php

namespace App\Actions;

use App\Enums\GroupStatus;
use App\Jobs\ProvisionClassroomGroup;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\RosterEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JoinClassroomGroup
{
    public function handle(User $student, Classroom $classroom, ClassroomGroup $group): RosterEntry
    {
        $entry = DB::transaction(function () use ($student, $classroom, $group): RosterEntry {
            $lockedGroup = ClassroomGroup::query()->lockForUpdate()->findOrFail($group->id);

            if ($lockedGroup->classroom_id !== $classroom->id) {
                abort(404);
            }

            if ($classroom->rosterEntries()->where('claimed_by_user_id', $student->id)->exists()) {
                throw ValidationException::withMessages(['team' => 'You already belong to a classroom team.']);
            }

            $entry = $classroom->rosterEntries()
                ->where('canvas_user_id', "github-user-{$student->id}")
                ->first();

            $attributes = [
                'classroom_group_id' => $lockedGroup->id,
                'claimed_by_user_id' => $student->id,
                'canvas_login_id' => $student->github_login ?? $student->email,
                'canvas_id' => "github-user-{$student->id}",
                'name' => $student->name,
                'sections' => 'Student-selected team',
                'claimed_at' => now(),
            ];

            if ($entry === null) {
                $entry = RosterEntry::query()->create([
                    'classroom_id' => $classroom->id,
                    'canvas_user_id' => "github-user-{$student->id}",
                    ...$attributes,
                ]);
            } else {
                $entry->update($attributes);
            }

            if ($lockedGroup->status !== GroupStatus::Ready) {
                $lockedGroup->update([
                    'status' => GroupStatus::Provisioning,
                    'provisioning_error' => null,
                ]);
            }

            return $entry;
        });

        ProvisionClassroomGroup::dispatch($group->id);

        return $entry;
    }
}
