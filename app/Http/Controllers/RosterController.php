<?php

namespace App\Http\Controllers;

use App\Actions\ImportRoster;
use App\Enums\RepositoryVisibility;
use App\Http\Requests\StoreRosterRequest;
use App\Models\Classroom;
use Illuminate\Http\RedirectResponse;

class RosterController extends Controller
{
    public function store(StoreRosterRequest $request, Classroom $classroom, ImportRoster $importRoster): RedirectResponse
    {
        $importRoster->handle(
            $classroom,
            $request->file('roster'),
            RepositoryVisibility::from($request->string('repository_visibility')->toString()),
        );

        return to_route('classrooms.students', $classroom)->with('success', 'Roster imported. Students can now use the classroom link.');
    }
}
