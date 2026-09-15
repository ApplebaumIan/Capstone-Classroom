<?php

namespace App\Jobs;

use App\GroupStatus;
use App\Models\ClassroomGroup;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ConfigureGitHubPages implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $timeout = 60;

    public function __construct(public int $classroomGroupId) {}

    public function handle(GitHubAppClient $github): void
    {
        $group = ClassroomGroup::query()->with('classroom')->findOrFail($this->classroomGroupId);

        if (! $github->hasPagesBranch($group)) {
            $this->release(30);

            return;
        }

        $group->update([
            'github_pages_url' => $github->configurePages($group),
            'provisioning_error' => null,
        ]);
    }

    public function uniqueId(): string
    {
        return (string) $this->classroomGroupId;
    }

    public function failed(?Throwable $exception): void
    {
        ClassroomGroup::query()->whereKey($this->classroomGroupId)->update([
            'status' => GroupStatus::Failed,
            'provisioning_error' => $exception?->getMessage() ?? 'GitHub Pages configuration failed.',
        ]);
    }
}
