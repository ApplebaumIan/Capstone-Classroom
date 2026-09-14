<?php

namespace App\Http\Controllers;

use App\Actions\ClaimRosterEntry;
use App\Http\Requests\ClaimRosterEntryRequest;
use App\Models\Classroom;
use App\Models\RosterEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RosterClaimController extends Controller
{
    public function show(Request $request, Classroom $classroom): Response
    {
        $claim = $classroom->rosterEntries()
            ->where('claimed_by_user_id', $request->user()->id)
            ->where('canvas_user_id', 'not like', 'github-user-%')
            ->with('group')
            ->first();
        $selectingTeam = $claim === null
            && $request->session()->get('onboarding.team_selection_classroom_id') === $classroom->id;

        return Inertia::render('join', [
            'classroom' => ['name' => $classroom->name, 'join_code' => $classroom->join_code],
            'student_team_creation_enabled' => $classroom->student_team_creation_enabled,
            'selecting_team' => $selectingTeam,
            'claim' => $claim === null ? null : [
                'name' => $claim->name,
                'sections' => $claim->sections,
                'group' => [
                    'name' => $claim->group->name,
                    'status' => $claim->group->status->value,
                    'error' => $claim->group->provisioning_error,
                    'repository_url' => $claim->group->github_repository_url,
                    'pages_url' => $claim->group->github_pages_url,
                ],
            ],
            'entries' => $claim === null && ! $selectingTeam
                ? $classroom->rosterEntries()
                    ->whereNull('claimed_by_user_id')
                    ->orderBy('name')
                    ->get(['id', 'name', 'sections'])
                : [],
            'available_groups' => $claim === null && $selectingTeam
                ? $classroom->groups()
                    ->withCount('rosterEntries')
                    ->orderBy('name')
                    ->get()
                    ->map(fn ($group): array => [
                        'id' => $group->id,
                        'name' => $group->name,
                        'status' => $group->status->value,
                        'student_count' => $group->roster_entries_count,
                    ])
                : [],
        ]);
    }

    public function store(
        ClaimRosterEntryRequest $request,
        Classroom $classroom,
        RosterEntry $rosterEntry,
        ClaimRosterEntry $claimRosterEntry,
    ): RedirectResponse {
        if ($rosterEntry->classroom_id !== $classroom->id) {
            abort(404);
        }

        $claimRosterEntry->handle($request->user(), $classroom, $rosterEntry);

        return to_route('classrooms.join', $classroom->join_code)
            ->with('success', 'Roster entry claimed. Your GitHub team is being prepared.');
    }
}
