<?php

namespace App\Http\Controllers;

use App\Enums\GroupStatus;
use App\Jobs\ProvisionClassroomGroup;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GroupProvisioningController extends Controller
{
    public function __invoke(Request $request, Classroom $classroom, ClassroomGroup $classroomGroup): RedirectResponse
    {
        abort_unless($classroomGroup->classroom_id === $classroom->id, 404);
        $classroomGroup->load('classroom');
        abort_unless($classroom->teacher_id === $request->user()->id, 404);
        abort_unless($classroom->hasActiveGitHubInstallation(), 409, 'The classroom GitHub App is not active.');

        $classroomGroup->update(['status' => GroupStatus::Provisioning, 'provisioning_error' => null]);
        ProvisionClassroomGroup::dispatch($classroomGroup->id);

        return to_route('classrooms.teams', $classroom)->with('success', 'Provisioning queued.');
    }
}
