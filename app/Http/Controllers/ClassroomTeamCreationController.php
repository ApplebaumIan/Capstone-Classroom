<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClassroomTeamCreationController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $classroom = $request->user()->classroom()->firstOrFail();
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $classroom->update([
            'student_team_creation_enabled' => $validated['enabled'],
        ]);

        return to_route('dashboard')->with('success', 'Student team creation setting updated.');
    }
}
