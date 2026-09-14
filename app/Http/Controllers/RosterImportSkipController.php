<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RosterImportSkipController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $classroom = $request->user()->classroom()->firstOrFail();

        abort_if($classroom->github_installation_id === null, 409, 'Install the GitHub App before skipping roster import.');

        if ($classroom->roster_imported_at === null) {
            $classroom->update(['roster_skipped_at' => now()]);
        }

        return to_route('dashboard')->with('success', 'Roster import skipped. You can import it whenever you are ready.');
    }
}
