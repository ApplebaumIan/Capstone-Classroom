<?php

namespace App\Http\Controllers;

use App\Jobs\RemoveStudentFromGitHubTeam;
use App\Models\RosterEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RosterClaimResetController extends Controller
{
    public function __invoke(Request $request, RosterEntry $rosterEntry): RedirectResponse
    {
        $rosterEntry->load(['classroom', 'group', 'claimedBy']);
        $request->user()->can('update', $rosterEntry->classroom) || abort(404);

        $githubLogin = $rosterEntry->claimedBy?->github_login;
        $groupId = $rosterEntry->classroom_group_id;

        DB::transaction(function () use ($rosterEntry): void {
            RosterEntry::query()->lockForUpdate()->findOrFail($rosterEntry->id)->update([
                'claimed_by_user_id' => null,
                'claimed_at' => null,
            ]);
        });

        if ($githubLogin !== null && $rosterEntry->group->github_team_slug !== null) {
            RemoveStudentFromGitHubTeam::dispatch($groupId, $githubLogin);
        }

        return to_route('dashboard')->with('success', 'The roster claim was reset.');
    }
}
