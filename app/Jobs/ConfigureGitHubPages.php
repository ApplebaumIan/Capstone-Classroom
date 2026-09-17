<?php

namespace App\Jobs;

use App\Enums\GroupStatus;
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

    public function __construct(public int $classroomGroupId, public ?string $expectedRepositoryId = null) {}

    public function handle(GitHubAppClient $github): void
    {
        $group = ClassroomGroup::query()->with('classroom')->findOrFail($this->classroomGroupId);

        if ($group->github_repository_missing_at !== null) {
            return;
        }

        if ($this->expectedRepositoryId !== null && $group->github_repository_id !== $this->expectedRepositoryId) {
            return;
        }

        $repositoryId = $group->github_repository_id;

        if (! $github->hasPagesBranch($group)) {
            $this->release(30);

            return;
        }

        $pagesUrl = $github->configurePages($group);

        ClassroomGroup::query()
            ->whereKey($group->id)
            ->where('github_repository_id', $repositoryId)
            ->whereNull('github_repository_missing_at')
            ->update([
                'github_pages_url' => $pagesUrl,
                'provisioning_error' => null,
            ]);
    }

    public function uniqueId(): string
    {
        return (string) $this->classroomGroupId;
    }

    public function failed(?Throwable $exception): void
    {
        $query = ClassroomGroup::query()
            ->whereKey($this->classroomGroupId)
            ->whereNull('github_repository_missing_at')
            ->whereNot('status', GroupStatus::Missing);

        if ($this->expectedRepositoryId !== null) {
            $query->where('github_repository_id', $this->expectedRepositoryId);
        }

        $query->update([
            'status' => GroupStatus::Failed,
            'provisioning_error' => $exception?->getMessage() ?? 'GitHub Pages configuration failed.',
        ]);
    }
}
