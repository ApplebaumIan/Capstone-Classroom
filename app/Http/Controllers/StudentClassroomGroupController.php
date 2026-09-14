<?php

namespace App\Http\Controllers;

use App\Actions\CreateClassroomGroup;
use App\Models\Classroom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StudentClassroomGroupController extends Controller
{
    public function store(
        Request $request,
        Classroom $classroom,
        CreateClassroomGroup $createClassroomGroup,
    ): RedirectResponse {
        $isTestingTeacher = $classroom->teacher_id === $request->user()->id;
        abort_if($classroom->github_installation_id === null, 409, 'The classroom GitHub App is not installed.');
        abort_unless($classroom->student_team_creation_enabled, 403);
        abort_unless(
            $isTestingTeacher || $classroom->pendingStudents()->whereKey($request->user()->id)->exists(),
            403,
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $createClassroomGroup->handle($classroom, $validated['name'], $request->user());

        if ($isTestingTeacher) {
            return to_route('classrooms.join', $classroom->join_code)
                ->with('success', 'Testing team creation queued.');
        }

        $request->session()->forget('onboarding.team_selection_classroom_id');
        $request->session()->put('onboarding.pending_dashboard_classroom_id', $classroom->id);

        return to_route('dashboard')->with('success', 'Your team is being created.');
    }
}
