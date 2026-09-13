<?php

namespace App\Http\Controllers;

use App\Actions\ImportRoster;
use App\Http\Requests\StoreRosterRequest;
use App\RepositoryVisibility;
use Illuminate\Http\RedirectResponse;

class RosterController extends Controller
{
    public function store(StoreRosterRequest $request, ImportRoster $importRoster): RedirectResponse
    {
        $importRoster->handle(
            $request->user()->classroom,
            $request->file('roster'),
            RepositoryVisibility::from($request->string('repository_visibility')->toString()),
        );

        return to_route('dashboard')->with('success', 'Roster imported. Students can now use the classroom link.');
    }
}
