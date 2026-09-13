<?php

namespace App\Http\Controllers;

use App\GroupStatus;
use App\Jobs\ProvisionClassroomGroup;
use App\Models\ClassroomGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GroupProvisioningController extends Controller
{
    public function __invoke(Request $request, ClassroomGroup $classroomGroup): RedirectResponse
    {
        $classroomGroup->load('classroom');
        $request->user()->can('update', $classroomGroup->classroom) || abort(404);

        $classroomGroup->update(['status' => GroupStatus::Provisioning, 'provisioning_error' => null]);
        ProvisionClassroomGroup::dispatch($classroomGroup->id);

        return to_route('dashboard')->with('success', 'Provisioning queued.');
    }
}
