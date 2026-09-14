<?php

namespace App\Http\Controllers;

use App\Actions\CreateClassroomGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClassroomGroupController extends Controller
{
    public function store(Request $request, CreateClassroomGroup $createClassroomGroup): RedirectResponse
    {
        $classroom = $request->user()->classroom()->firstOrFail();
        abort_if($classroom->github_installation_id === null, 409, 'Install the GitHub App before creating teams.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $createClassroomGroup->handle($classroom, $validated['name']);

        return to_route('dashboard')->with('success', 'Team creation queued.');
    }
}
