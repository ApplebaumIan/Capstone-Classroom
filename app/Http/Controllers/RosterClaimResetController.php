<?php

namespace App\Http\Controllers;

use App\Jobs\RemoveStudentFromGitHubTeam;
use App\Models\Classroom;
use App\Models\RosterEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RosterClaimResetController extends Controller
{
    public function __invoke(Request $request, Classroom $classroom, RosterEntry $rosterEntry): RedirectResponse
    {
        abort_unless($rosterEntry->classroom_id === $classroom->id, 404);
        abort_if(
            $rosterEntry->claimed_by_user_id === $classroom->teacher_id
                && str_starts_with($rosterEntry->canvas_user_id, 'github-user-'),
            404,
        );
        $rosterEntry->load(['classroom', 'group', 'claimedBy']);
        abort_unless($classroom->teacher_id === $request->user()->id, 404);

        $githubLogin = $rosterEntry->claimedBy?->github_login;
        $claimedUserId = $rosterEntry->claimed_by_user_id;
        $groupId = $rosterEntry->classroom_group_id;

        DB::transaction(function () use ($claimedUserId, $rosterEntry): void {
            RosterEntry::query()->lockForUpdate()->findOrFail($rosterEntry->id)->update([
                'claimed_by_user_id' => null,
                'claimed_at' => null,
            ]);

            if ($claimedUserId !== null) {
                $rosterEntry->classroom->pendingStudents()->syncWithoutDetaching([$claimedUserId]);
            }
        });

        if ($githubLogin !== null && $rosterEntry->group->github_team_slug !== null) {
            RemoveStudentFromGitHubTeam::dispatch($groupId, $githubLogin);
        }

        return to_route('classrooms.students', $classroom)->with('success', 'The roster claim was reset.');
    }
}
