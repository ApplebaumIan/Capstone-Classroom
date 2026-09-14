<?php

namespace App\Http\Controllers;

use App\Actions\ClaimRosterEntry;
use App\Models\Classroom;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PendingRosterClaimController extends Controller
{
    public function __invoke(
        Request $request,
        Classroom $classroom,
        User $pendingStudent,
        ClaimRosterEntry $claimRosterEntry,
    ): RedirectResponse {
        abort_unless($classroom->teacher_id === $request->user()->id, 404);
        $classroom->pendingStudents()->whereKey($pendingStudent->id)->exists() || abort(404);

        $validated = $request->validate([
            'roster_entry_id' => ['required', 'integer'],
        ]);
        $rosterEntry = $classroom->rosterEntries()
            ->whereKey($validated['roster_entry_id'])
            ->whereNull('claimed_by_user_id')
            ->firstOrFail();

        $claimRosterEntry->handle($pendingStudent, $classroom, $rosterEntry);

        $studentName = $pendingStudent->github_login === null
            ? $pendingStudent->name
            : "@{$pendingStudent->github_login}";

        return to_route('classrooms.students', $classroom)->with('success', "{$studentName} was linked to {$rosterEntry->name}.");
    }
}
