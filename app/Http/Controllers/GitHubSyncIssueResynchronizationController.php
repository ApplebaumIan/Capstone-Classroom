<?php

namespace App\Http\Controllers;

use App\Actions\ResynchronizeGitHubSyncIssue;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\GitHubSyncIssue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GitHubSyncIssueResynchronizationController extends Controller
{
    public function __invoke(
        Request $request,
        Classroom $classroom,
        ClassroomGroup $classroomGroup,
        GitHubSyncIssue $githubSyncIssue,
        ResynchronizeGitHubSyncIssue $resynchronizeGitHubSyncIssue,
    ): RedirectResponse {
        abort_unless($classroom->teacher_id === $request->user()->id, 404);
        abort_unless($classroomGroup->classroom_id === $classroom->id, 404);
        abort_unless($githubSyncIssue->classroom_group_id === $classroomGroup->id, 404);
        abort_unless($classroom->hasActiveGitHubInstallation(), 409, 'The classroom GitHub App is not active.');

        $resynchronizeGitHubSyncIssue->handle($githubSyncIssue);

        return back()->with('success', 'GitHub resynchronization was queued.');
    }
}
