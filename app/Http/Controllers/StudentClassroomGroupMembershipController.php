<?php

namespace App\Http\Controllers;

use App\Actions\JoinClassroomGroup;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StudentClassroomGroupMembershipController extends Controller
{
    public function __invoke(
        Request $request,
        Classroom $classroom,
        ClassroomGroup $classroomGroup,
        JoinClassroomGroup $joinClassroomGroup,
    ): RedirectResponse {
        abort_if($classroom->github_installation_id === null, 409, 'The classroom GitHub App is not installed.');
        abort_unless($classroom->pendingStudents()->whereKey($request->user()->id)->exists(), 403);

        $joinClassroomGroup->handle($request->user(), $classroom, $classroomGroup);
        $request->session()->forget('onboarding.team_selection_classroom_id');
        $request->session()->put('onboarding.pending_dashboard_classroom_id', $classroom->id);

        return to_route('dashboard')->with('success', "You joined {$classroomGroup->name}.");
    }
}
