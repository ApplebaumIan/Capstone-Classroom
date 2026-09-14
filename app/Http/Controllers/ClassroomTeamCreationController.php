<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClassroomTeamCreationController extends Controller
{
    public function update(Request $request, Classroom $classroom): RedirectResponse
    {
        abort_unless($classroom->teacher_id === $request->user()->id, 404);
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $classroom->update([
            'student_team_creation_enabled' => $validated['enabled'],
        ]);

        return to_route('classrooms.teams', $classroom)->with('success', 'Student team creation setting updated.');
    }
}
