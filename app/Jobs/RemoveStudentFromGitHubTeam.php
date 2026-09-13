<?php

namespace App\Jobs;

use App\Models\ClassroomGroup;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RemoveStudentFromGitHubTeam implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public function __construct(public int $classroomGroupId, public string $githubLogin) {}

    public function handle(GitHubAppClient $github): void
    {
        $group = ClassroomGroup::query()->with('classroom')->find($this->classroomGroupId);

        if ($group?->github_team_slug === null) {
            return;
        }

        $github->removeTeamMember($group, $this->githubLogin);
    }
}
