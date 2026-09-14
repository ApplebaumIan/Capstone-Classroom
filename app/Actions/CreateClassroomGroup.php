<?php

namespace App\Actions;

use App\GroupStatus;
use App\Jobs\ProvisionClassroomGroup;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\RosterEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateClassroomGroup
{
    public function handle(Classroom $classroom, string $name, ?User $student = null): ClassroomGroup
    {
        $repositoryName = Str::slug($name);

        if ($repositoryName === '') {
            throw ValidationException::withMessages(['name' => 'The team name must contain letters or numbers.']);
        }

        $group = DB::transaction(function () use ($classroom, $name, $repositoryName, $student): ClassroomGroup {
            if ($classroom->groups()->where('repository_name', $repositoryName)->exists()) {
                throw ValidationException::withMessages(['name' => 'A team with that name already exists.']);
            }

            if ($student !== null && $classroom->rosterEntries()->where('claimed_by_user_id', $student->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'You already belong to a classroom team.']);
            }

            if ($student !== null && $classroom->rosterEntries()->where('canvas_user_id', "github-user-{$student->id}")->exists()) {
                throw ValidationException::withMessages(['name' => 'You already created a classroom team. Select your existing name instead.']);
            }

            $group = $classroom->groups()->create([
                'name' => $name,
                'repository_name' => $repositoryName,
                'status' => GroupStatus::Provisioning,
                'created_manually' => true,
            ]);

            if ($student !== null) {
                RosterEntry::query()->create([
                    'classroom_id' => $classroom->id,
                    'classroom_group_id' => $group->id,
                    'claimed_by_user_id' => $student->id,
                    'canvas_user_id' => "github-user-{$student->id}",
                    'canvas_login_id' => $student->github_login ?? $student->email,
                    'canvas_id' => "github-user-{$student->id}",
                    'name' => $student->name,
                    'sections' => $student->id === $classroom->teacher_id
                        ? 'Teacher testing team'
                        : 'Student-created team',
                    'claimed_at' => now(),
                ]);

            }

            if ($classroom->roster_imported_at === null) {
                $classroom->update(['roster_skipped_at' => $classroom->roster_skipped_at ?? now()]);
            }

            return $group;
        });

        ProvisionClassroomGroup::dispatch($group->id);

        return $group;
    }
}
