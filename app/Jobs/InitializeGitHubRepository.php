<?php

namespace App\Jobs;

use App\GroupStatus;
use App\Models\ClassroomGroup;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;

class InitializeGitHubRepository implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120, 300];

    public int $timeout = 60;

    public function __construct(public int $classroomGroupId) {}

    public function handle(GitHubAppClient $github): void
    {
        $group = ClassroomGroup::query()->with('classroom')->findOrFail($this->classroomGroupId);

        try {
            if (! $github->hasRepositoryTemplateContents($group)) {
                $group->update(['provisioning_error' => null]);
                $this->release(30);

                return;
            }

            $github->triggerPagesDeployment($group);
        } catch (RequestException $exception) {
            $group->update(['provisioning_error' => $exception->getMessage()]);

            if (($delay = $this->rateLimitDelay($exception)) !== null) {
                $this->release($delay);

                return;
            }

            throw $exception;
        } catch (Throwable $exception) {
            $group->update(['provisioning_error' => $exception->getMessage()]);

            throw $exception;
        }

        $group->update([
            'status' => GroupStatus::Ready,
            'provisioning_error' => null,
        ]);

        ConfigureGitHubPages::dispatch($group->id)->delay(now()->addSeconds(30));
    }

    public function uniqueId(): string
    {
        return (string) $this->classroomGroupId;
    }

    public function failed(?Throwable $exception): void
    {
        $group = ClassroomGroup::query()->find($this->classroomGroupId);

        if ($group === null) {
            return;
        }

        $message = $exception instanceof MaxAttemptsExceededException
            ? ($group->provisioning_error ?? 'GitHub repository template contents did not become available in time.')
            : ($exception?->getMessage() ?? 'GitHub repository initialization failed.');

        $group->update([
            'status' => GroupStatus::Failed,
            'provisioning_error' => $message,
        ]);
    }

    private function rateLimitDelay(RequestException $exception): ?int
    {
        if (! in_array($exception->response->status(), [403, 429], true)) {
            return null;
        }

        $retryAfter = filter_var($exception->response->header('Retry-After'), FILTER_VALIDATE_INT);

        if ($retryAfter !== false) {
            return max(1, (int) $retryAfter);
        }

        $resetAt = filter_var($exception->response->header('X-RateLimit-Reset'), FILTER_VALIDATE_INT);

        if ($exception->response->header('X-RateLimit-Remaining') === '0' && $resetAt !== false) {
            return max(1, (int) $resetAt - now()->getTimestamp());
        }

        return $exception->response->status() === 429 ? 60 : null;
    }
}
