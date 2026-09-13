<?php

namespace App\Jobs;

use App\GroupStatus;
use App\Models\ClassroomGroup;
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

        $group->update(['status' => GroupStatus::Provisioning, 'provisioning_error' => null]);

        if ($group->github_team_id === null) {
            $team = $github->ensureTeam($group);
            $group->update([
                'github_team_id' => (string) $team['id'],
                'github_team_slug' => $team['slug'],
                'github_team_url' => $team['html_url'],
            ]);
        }

        if ($group->github_repository_id === null) {
            $repository = $github->ensureRepository($group);
            $group->update([
                'github_repository_id' => (string) $repository['id'],
                'github_repository_url' => $repository['html_url'],
            ]);
        }

        $github->grantTeamRepository($group);

        foreach ($group->rosterEntries as $entry) {
            if ($entry->claimedBy?->github_login !== null) {
                $github->addTeamMember($group, $entry->claimedBy->github_login);
            }
        }

        $github->triggerPagesDeployment($group);
        $group->update(['status' => GroupStatus::Ready]);

        ConfigureGitHubPages::dispatch($group->id)->delay(now()->addSeconds(30));
    }

    public function uniqueId(): string
    {
        return (string) $this->classroomGroupId;
    }

    public function failed(?Throwable $exception): void
    {
        ClassroomGroup::query()->whereKey($this->classroomGroupId)->update([
            'status' => GroupStatus::Failed,
            'provisioning_error' => $exception?->getMessage() ?? 'GitHub provisioning failed.',
        ]);
    }
}
