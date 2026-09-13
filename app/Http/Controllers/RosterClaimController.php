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
            ->with('group')
            ->first();

        return Inertia::render('join', [
            'classroom' => ['name' => $classroom->name, 'join_code' => $classroom->join_code],
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
            'entries' => $claim === null
                ? $classroom->rosterEntries()
                    ->whereNull('claimed_by_user_id')
                    ->orderBy('name')
                    ->get(['id', 'name', 'sections'])
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
