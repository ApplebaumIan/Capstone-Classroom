<?php

namespace App\Actions;

use App\GitHubInstallationStatus;
use App\GitHubSyncIssueType;
use App\GitHubSyncResolution;
use App\GroupStatus;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\GitHubSyncIssue;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ProcessGitHubWebhook
{
    public function __construct(public GitHubAppClient $github) {}

    /** @param array<string, mixed> $payload */
    public function handle(string $event, string $deliveryId, array $payload): void
    {
        if ($deliveryId === '') {
            return;
        }

        match ($event) {
            'installation' => $this->installation($payload),
            'repository' => $this->repository($deliveryId, $payload),
            'team' => $this->team($deliveryId, $payload),
            'membership' => $this->membership($deliveryId, $payload),
            default => null,
        };
    }

    /** @param array<string, mixed> $payload */
    private function installation(array $payload): void
    {
        $action = Arr::get($payload, 'action');
        $installationId = Arr::get($payload, 'installation.id');

        if (! in_array($action, ['suspend', 'deleted', 'unsuspend'], true)) {
            return;
        }

        if (! is_int($installationId) && ! is_string($installationId)) {
            return;
        }

        $status = $action === 'deleted'
            ? GitHubInstallationStatus::Deleted
            : $this->github->installationStatus((string) $installationId);

        $query = Classroom::query()
            ->where('github_installation_id', (string) $installationId)
            ->when(
                $status !== GitHubInstallationStatus::Deleted,
                fn ($query) => $query->where(fn ($query) => $query
                    ->whereNull('github_installation_status')
                    ->orWhereNot('github_installation_status', GitHubInstallationStatus::Deleted)),
            );

        $query->update(['github_installation_status' => $status]);
    }

    /** @param array<string, mixed> $payload */
    private function repository(string $deliveryId, array $payload): void
    {
        $action = Arr::get($payload, 'action');

        if (! in_array($action, ['deleted', 'renamed'], true)) {
            return;
        }

        $this->withRepositoryGroup($payload, function (ClassroomGroup $group) use ($action, $deliveryId, $payload): void {
            if ($action === 'deleted') {
                $group->update([
                    'github_repository_id' => null,
                    'github_repository_url' => null,
                    'github_pages_url' => null,
                    'github_repository_missing_at' => now(),
                    'status' => GroupStatus::Missing,
                    'provisioning_error' => null,
                ]);
                $this->openIssue($group, $deliveryId, GitHubSyncIssueType::RepositoryDeleted);

                return;
            }

            $oldName = $group->repository_name;
            $newName = Arr::get($payload, 'repository.name');

            if (! is_string($newName) || $newName === '') {
                return;
            }

            $group->update([
                'repository_name' => $newName,
                'github_repository_url' => Arr::get($payload, 'repository.html_url'),
            ]);
            $this->recordObservedIssue($group, $deliveryId, GitHubSyncIssueType::RepositoryRenamed, [
                'old_name' => $oldName,
                'new_name' => $newName,
            ]);
        });
    }

    /** @param array<string, mixed> $payload */
    private function team(string $deliveryId, array $payload): void
    {
        $action = Arr::get($payload, 'action');

        if (! in_array($action, ['deleted', 'edited', 'added_to_repository', 'removed_from_repository'], true)) {
            return;
        }

        $this->withTeamGroup($payload, function (ClassroomGroup $group) use ($action, $deliveryId, $payload): void {
            if ($action === 'deleted') {
                $group->update([
                    'github_team_id' => null,
                    'github_team_name' => null,
                    'github_team_slug' => null,
                    'github_team_url' => null,
                    'github_team_repository_access' => null,
                    'github_team_missing_at' => now(),
                    'status' => GroupStatus::Missing,
                    'provisioning_error' => null,
                ]);
                $this->openIssue($group, $deliveryId, GitHubSyncIssueType::TeamDeleted);

                return;
            }

            if ($action === 'edited') {
                $oldName = $group->github_team_name ?? $group->name;
                $group->update([
                    'github_team_name' => Arr::get($payload, 'team.name'),
                    'github_team_slug' => Arr::get($payload, 'team.slug'),
                    'github_team_url' => Arr::get($payload, 'team.html_url'),
                ]);
                $this->recordObservedIssue($group, $deliveryId, GitHubSyncIssueType::TeamEdited, [
                    'old_name' => $oldName,
                    'new_name' => Arr::get($payload, 'team.name'),
                ]);

                return;
            }

            if ((string) Arr::get($payload, 'repository.id') !== $group->github_repository_id) {
                return;
            }

            if ($action === 'removed_from_repository') {
                $group->update([
                    'github_team_repository_access' => false,
                    'status' => GroupStatus::Missing,
                ]);
                $this->openIssue($group, $deliveryId, GitHubSyncIssueType::TeamRepositoryAccessRemoved);

                return;
            }

            $hasPendingRemoval = GitHubSyncIssue::query()
                ->whereBelongsTo($group, 'classroomGroup')
                ->where('type', GitHubSyncIssueType::TeamRepositoryAccessRemoved)
                ->whereNull('resolved_at')
                ->exists();

            if ($hasPendingRemoval) {
                return;
            }

            $group->update(['github_team_repository_access' => true]);
            $this->restoreReadyStatus($group);
        });
    }

    /** @param array<string, mixed> $payload */
    private function membership(string $deliveryId, array $payload): void
    {
        $action = Arr::get($payload, 'action');

        if (! in_array($action, ['added', 'removed'], true)) {
            return;
        }

        $githubUserId = (string) Arr::get($payload, 'member.id', '');
        $githubLogin = Arr::get($payload, 'member.login');

        if ($githubUserId === '' || ! is_string($githubLogin)) {
            return;
        }

        $this->withTeamGroup($payload, function (ClassroomGroup $group) use ($action, $deliveryId, $githubUserId, $githubLogin): void {
            $isExpected = $group->rosterEntries()
                ->whereHas('claimedBy', fn ($query) => $query->where('github_id', $githubUserId))
                ->exists();

            if (($action === 'added') === $isExpected) {
                return;
            }

            $type = $action === 'added'
                ? GitHubSyncIssueType::MembershipAdded
                : GitHubSyncIssueType::MembershipRemoved;

            $this->openIssue($group, $deliveryId, $type, $githubUserId, $githubLogin);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(ClassroomGroup): void  $callback
     */
    private function withRepositoryGroup(array $payload, callable $callback): void
    {
        $this->withGroup('github_repository_id', Arr::get($payload, 'repository.id'), $payload, $callback);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(ClassroomGroup): void  $callback
     */
    private function withTeamGroup(array $payload, callable $callback): void
    {
        $this->withGroup('github_team_id', Arr::get($payload, 'team.id'), $payload, $callback);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(ClassroomGroup): void  $callback
     */
    private function withGroup(string $column, mixed $resourceId, array $payload, callable $callback): void
    {
        $installationId = Arr::get($payload, 'installation.id');

        if (! is_int($resourceId) && ! is_string($resourceId)) {
            return;
        }

        if (! is_int($installationId) && ! is_string($installationId)) {
            return;
        }

        DB::transaction(function () use ($column, $resourceId, $installationId, $callback): void {
            $group = ClassroomGroup::query()
                ->where($column, (string) $resourceId)
                ->whereHas('classroom', fn ($query) => $query->where('github_installation_id', (string) $installationId))
                ->lockForUpdate()
                ->first();

            if ($group !== null) {
                $callback($group);
            }
        });
    }

    /** @param array<string, mixed>|null $metadata */
    private function openIssue(
        ClassroomGroup $group,
        string $deliveryId,
        GitHubSyncIssueType $type,
        ?string $githubUserId = null,
        ?string $githubLogin = null,
        ?array $metadata = null,
    ): void {
        $existingIssue = GitHubSyncIssue::query()
            ->whereBelongsTo($group, 'classroomGroup')
            ->where('type', $type)
            ->where('github_user_id', $githubUserId)
            ->whereNull('resolved_at')
            ->first();

        if ($existingIssue !== null) {
            return;
        }

        GitHubSyncIssue::query()->firstOrCreate(
            [
                'delivery_id' => $deliveryId,
                'classroom_group_id' => $group->id,
                'type' => $type,
            ],
            [
                'github_user_id' => $githubUserId,
                'github_login' => $githubLogin,
                'metadata' => $metadata,
                'detected_at' => now(),
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function recordObservedIssue(
        ClassroomGroup $group,
        string $deliveryId,
        GitHubSyncIssueType $type,
        array $metadata,
    ): void {
        GitHubSyncIssue::query()->firstOrCreate(
            [
                'delivery_id' => $deliveryId,
                'classroom_group_id' => $group->id,
                'type' => $type,
            ],
            [
                'metadata' => $metadata,
                'detected_at' => now(),
                'resolved_at' => now(),
                'resolution' => GitHubSyncResolution::Observed,
            ],
        );
    }

    private function restoreReadyStatus(ClassroomGroup $group): void
    {
        if (
            $group->status === GroupStatus::Missing
            && $group->github_team_missing_at === null
            && $group->github_repository_missing_at === null
            && $group->github_team_repository_access !== false
        ) {
            $group->update(['status' => GroupStatus::Ready]);
        }
    }
}
