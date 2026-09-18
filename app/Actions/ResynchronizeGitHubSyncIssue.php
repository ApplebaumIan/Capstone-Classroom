<?php

namespace App\Actions;

use App\Enums\GitHubSyncIssueType;
use App\Jobs\ReconcileGitHubSyncIssue;
use App\Models\GitHubSyncIssue;

class ResynchronizeGitHubSyncIssue
{
    public function handle(GitHubSyncIssue $issue): void
    {
        if ($issue->resolved_at !== null) {
            return;
        }

        if (! in_array($issue->type, [GitHubSyncIssueType::MembershipAdded, GitHubSyncIssueType::MembershipRemoved], true)) {
            abort(409, 'This GitHub change cannot be resynchronized.');
        }

        ReconcileGitHubSyncIssue::dispatch($issue->id);
    }
}
