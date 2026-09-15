<?php

namespace App\Http\Controllers;

use App\Actions\CreateClassroomGroup;
use App\Models\Classroom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClassroomGroupController extends Controller
{
    public function store(Request $request, Classroom $classroom, CreateClassroomGroup $createClassroomGroup): RedirectResponse
    {
        abort_unless($classroom->teacher_id === $request->user()->id, 404);
        abort_unless($classroom->hasActiveGitHubInstallation(), 409, 'Install the GitHub App before creating teams.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $createClassroomGroup->handle($classroom, $validated['name']);

        return to_route('classrooms.teams', $classroom)->with('success', 'Team creation queued.');
    }
}
