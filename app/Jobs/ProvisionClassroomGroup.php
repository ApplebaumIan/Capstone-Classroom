<?php

namespace App\Jobs;

use App\GitHubSyncIssueType;
use App\GitHubSyncResolution;
use App\GroupStatus;
use App\Models\ClassroomGroup;
use App\Models\GitHubSyncIssue;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProvisionClassroomGroup implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public int $timeout = 120;

    public function __construct(public int $classroomGroupId) {}

    public function handle(GitHubAppClient $github): void
    {
        $group = ClassroomGroup::query()
            ->with(['classroom', 'rosterEntries.claimedBy'])
            ->findOrFail($this->classroomGroupId);

        $claimed = ClassroomGroup::query()
            ->whereKey($group->id)
            ->whereNot('status', GroupStatus::Missing)
            ->update(['status' => GroupStatus::Provisioning, 'provisioning_error' => null]);

        if ($claimed === 0) {
            return;
        }

        $group->refresh();

        if ($group->github_team_id === null) {
            $team = $github->ensureTeam($group);
            $group->update([
                'github_team_id' => (string) $team['id'],
                'github_team_name' => $team['name'],
                'github_team_slug' => $team['slug'],
                'github_team_url' => $team['html_url'],
                'github_team_missing_at' => null,
            ]);
            $this->resolveIssues($group, GitHubSyncIssueType::TeamDeleted);
        }

        if ($group->github_repository_id === null) {
            $repository = $github->ensureRepository($group);
            $group->update([
                'github_repository_id' => (string) $repository['id'],
                'github_repository_url' => $repository['html_url'],
                'github_repository_missing_at' => null,
            ]);
            $this->resolveIssues($group, GitHubSyncIssueType::RepositoryDeleted);
        }

        $github->grantTeamRepository($group);
        $group->update(['github_team_repository_access' => true]);
        $this->resolveIssues($group, GitHubSyncIssueType::TeamRepositoryAccessRemoved);

        foreach ($group->rosterEntries as $entry) {
            if ($entry->claimedBy?->github_login !== null) {
                $github->addTeamMember($group, $entry->claimedBy->github_login);
            }
        }

        InitializeGitHubRepository::dispatch($group->id, $group->github_repository_id)->delay(now()->addSeconds(5));
    }

    public function uniqueId(): string
    {
        return (string) $this->classroomGroupId;
    }

    public function failed(?Throwable $exception): void
    {
        ClassroomGroup::query()
            ->whereKey($this->classroomGroupId)
            ->where('status', GroupStatus::Provisioning)
            ->update([
                'status' => GroupStatus::Failed,
                'provisioning_error' => $exception?->getMessage() ?? 'GitHub provisioning failed.',
            ]);
    }

    private function resolveIssues(ClassroomGroup $group, GitHubSyncIssueType $type): void
    {
        GitHubSyncIssue::query()
            ->whereBelongsTo($group, 'classroomGroup')
            ->where('type', $type)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'resolution' => GitHubSyncResolution::Resynced,
            ]);
    }
}
