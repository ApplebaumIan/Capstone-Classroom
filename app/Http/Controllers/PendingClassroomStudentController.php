<?php

namespace App\Http\Controllers;

use App\Models\Classroom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PendingClassroomStudentController extends Controller
{
    public function store(Request $request, Classroom $classroom): RedirectResponse
    {
        $hasClaim = $classroom->rosterEntries()
            ->where('claimed_by_user_id', $request->user()->id)
            ->exists();

        if ($hasClaim) {
            return to_route('classrooms.join', $classroom->join_code);
        }

        $classroom->pendingStudents()->syncWithoutDetaching([$request->user()->id]);
        $request->session()->put('onboarding.team_selection_classroom_id', $classroom->id);

        return to_route('classrooms.join', $classroom->join_code);
    }
}
