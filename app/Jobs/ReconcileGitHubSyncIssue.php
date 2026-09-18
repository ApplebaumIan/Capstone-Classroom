<?php

namespace App\Jobs;

use App\Enums\GitHubSyncIssueType;
use App\Enums\GitHubSyncResolution;
use App\Models\GitHubSyncIssue;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconcileGitHubSyncIssue implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 60;

    public function __construct(public int $githubSyncIssueId) {}

    public function handle(GitHubAppClient $github): void
    {
        $issue = DB::transaction(function (): GitHubSyncIssue {
            $issue = GitHubSyncIssue::query()
                ->with('classroomGroup.classroom')
                ->lockForUpdate()
                ->findOrFail($this->githubSyncIssueId);

            if ($issue->resolved_at === null && $issue->reconciling_at === null) {
                $issue->update(['reconciling_at' => now()]);
            }

            return $issue;
        });

        if ($issue->resolved_at !== null) {
            return;
        }

        match ($issue->type) {
            GitHubSyncIssueType::MembershipAdded => $github->removeTeamMember(
                $issue->classroomGroup,
                (string) $issue->github_login,
            ),
            GitHubSyncIssueType::MembershipRemoved => $github->addTeamMember(
                $issue->classroomGroup,
                (string) $issue->github_login,
            ),
            default => throw new \RuntimeException('This GitHub sync issue cannot be reconciled.'),
        };

        DB::transaction(function (): void {
            GitHubSyncIssue::query()
                ->whereKey($this->githubSyncIssueId)
                ->whereNull('resolved_at')
                ->lockForUpdate()
                ->update([
                    'resolved_at' => now(),
                    'reconciling_at' => null,
                    'resolution' => GitHubSyncResolution::Resynced,
                ]);
        });
    }

    public function uniqueId(): string
    {
        return (string) $this->githubSyncIssueId;
    }

    public function failed(?Throwable $exception): void
    {
        $issue = GitHubSyncIssue::query()->find($this->githubSyncIssueId);

        if ($issue === null || $issue->resolved_at !== null) {
            return;
        }

        $issue->update([
            'reconciling_at' => null,
            'metadata' => [
                ...($issue->metadata ?? []),
                'sync_error' => $exception?->getMessage() ?? 'GitHub resynchronization failed.',
            ],
        ]);
    }
}
