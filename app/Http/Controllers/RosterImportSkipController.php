<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RosterImportSkipController extends Controller
{
    public function __invoke(Request $request, Classroom $classroom): RedirectResponse
    {
        abort_unless($classroom->teacher_id === $request->user()->id, 404);

        abort_unless($classroom->hasActiveGitHubInstallation(), 409, 'Install the GitHub App before skipping roster import.');

        if ($classroom->roster_imported_at === null) {
            $classroom->update(['roster_skipped_at' => now()]);
        }

        return to_route('classrooms.students', $classroom)->with('success', 'Roster import skipped. You can import it whenever you are ready.');
    }
}
