<?php

namespace App\Http\Controllers;

use App\Actions\AcceptGitHubSyncIssue;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\GitHubSyncIssue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GitHubSyncIssueAcceptanceController extends Controller
{
    public function __invoke(
        Request $request,
        Classroom $classroom,
        ClassroomGroup $classroomGroup,
        GitHubSyncIssue $githubSyncIssue,
        AcceptGitHubSyncIssue $acceptGitHubSyncIssue,
    ): RedirectResponse {
        abort_unless($classroom->teacher_id === $request->user()->id, 404);
        abort_unless($classroomGroup->classroom_id === $classroom->id, 404);
        abort_unless($githubSyncIssue->classroom_group_id === $classroomGroup->id, 404);
        abort_unless($classroom->hasActiveGitHubInstallation(), 409, 'The classroom GitHub App is not active.');

        $acceptGitHubSyncIssue->handle($githubSyncIssue);

        return back()->with('success', 'The GitHub membership change was accepted.');
    }
}
