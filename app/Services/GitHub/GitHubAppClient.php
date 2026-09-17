<?php

namespace App\Services\GitHub;

use App\Enums\GitHubInstallationStatus;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GitHubAppClient
{
    /** @return array<int, array{id: int|string, account: array{id: int|string, login: string, avatar_url?: string}, target_type: string}> */
    public function accessibleInstallations(string $userAccessToken): array
    {
        $installations = $this->request($userAccessToken)
            ->get('/user/installations', ['per_page' => 100])
            ->throw()
            ->json('installations', []);

        return array_values(array_filter(
            $installations,
            static fn (array $installation): bool => ($installation['target_type'] ?? null) === 'Organization',
        ));
    }

    public function installationStatus(string $installationId): GitHubInstallationStatus
    {
        $response = $this->request($this->appJwt())
            ->get('/app/installations/'.$this->segment($installationId));

        if ($response->notFound()) {
            return GitHubInstallationStatus::Deleted;
        }

        return $response->throw()->json('suspended_at') === null
            ? GitHubInstallationStatus::Active
            : GitHubInstallationStatus::Suspended;
    }

    /** @return array<string, mixed> */
    public function ensureTeam(ClassroomGroup $group): array
    {
        $classroom = $group->classroom;
        $slug = str($group->name)->slug()->toString();
        $path = '/orgs/'.$this->segment($classroom->github_organization_login).'/teams/'.$this->segment($slug);
        $marker = $this->marker($classroom);
        $response = $this->installationRequest($classroom)->get($path);

        if ($response->successful()) {
            $team = $response->json();

            if (! str_contains((string) ($team['description'] ?? ''), $marker)) {
                throw new RuntimeException("A GitHub team named {$group->name} already exists and is not managed by this classroom.");
            }

            return $team;
        }

        if (! $response->notFound()) {
            $response->throw();
        }

        return $this->installationRequest($classroom)
            ->post('/orgs/'.$this->segment($classroom->github_organization_login).'/teams', [
                'name' => $group->name,
                'description' => "{$marker}. Canvas group provisioned by Capstone Classroom.",
                'privacy' => 'closed',
                'notification_setting' => 'notifications_enabled',
            ])
            ->throw()
            ->json();
    }

    /** @return array<string, mixed> */
    public function ensureRepository(ClassroomGroup $group): array
    {
        $classroom = $group->classroom;
        $path = '/repos/'.$this->segment($classroom->github_organization_login).'/'.$this->segment($group->repository_name);
        $marker = $this->marker($classroom);
        $response = $this->installationRequest($classroom)->get($path);

        if ($response->successful()) {
            $repository = $response->json();

            if (! str_contains((string) ($repository['description'] ?? ''), $marker)) {
                throw new RuntimeException("A GitHub repository named {$group->repository_name} already exists and is not managed by this classroom.");
            }

            Log::info('Found existing GitHub classroom repository.', [
                'classroom_group_id' => $group->id,
                'github_repository_id' => $repository['id'] ?? null,
                'default_branch' => $repository['default_branch'] ?? null,
            ]);

            return $repository;
        }

        if (! $response->notFound()) {
            $response->throw();
        }

        $repository = $this->installationRequest($classroom)
            ->post('/repos/'.config('services.github.template_owner').'/'.config('services.github.template_repository').'/generate', [
                'owner' => $classroom->github_organization_login,
                'name' => $group->repository_name,
                'description' => "{$marker}. {$group->name} project repository.",
                'private' => $classroom->repository_visibility->value === 'private',
                'include_all_branches' => false,
            ])
            ->throw()
            ->json();

        Log::info('Generated GitHub classroom repository.', [
            'classroom_group_id' => $group->id,
            'github_repository_id' => $repository['id'] ?? null,
            'default_branch' => $repository['default_branch'] ?? null,
        ]);

        return $repository;
    }

    public function grantTeamRepository(ClassroomGroup $group): void
    {
        $classroom = $group->classroom;

        $this->installationRequest($classroom)
            ->put('/orgs/'.$this->segment($classroom->github_organization_login).'/teams/'.$this->segment($group->github_team_slug).'/repos/'.$this->segment($classroom->github_organization_login).'/'.$this->segment($group->repository_name), [
                'permission' => 'admin',
            ])
            ->throw();
    }

    /** @return array<string, mixed> */
    public function addTeamMember(ClassroomGroup $group, string $githubLogin): array
    {
        return $this->installationRequest($group->classroom)
            ->put('/orgs/'.$this->segment($group->classroom->github_organization_login).'/teams/'.$this->segment($group->github_team_slug).'/memberships/'.$this->segment($githubLogin), [
                'role' => 'member',
            ])
            ->throw()
            ->json();
    }

    public function removeTeamMember(ClassroomGroup $group, string $githubLogin): void
    {
        $response = $this->installationRequest($group->classroom)
            ->delete('/orgs/'.$this->segment($group->classroom->github_organization_login).'/teams/'.$this->segment($group->github_team_slug).'/memberships/'.$this->segment($githubLogin));

        if (! $response->successful() && ! $response->notFound()) {
            $response->throw();
        }
    }

    public function teamHasMember(ClassroomGroup $group, string $githubLogin): bool
    {
        $response = $this->installationRequest($group->classroom)
            ->get('/orgs/'.$this->segment($group->classroom->github_organization_login).'/teams/'.$this->segment($group->github_team_slug).'/memberships/'.$this->segment($githubLogin));

        if ($response->notFound()) {
            return false;
        }

        return $response->throw()->successful();
    }

    public function triggerPagesDeployment(ClassroomGroup $group): void
    {
        $classroom = $group->classroom;
        $path = '/repos/'.$this->segment($classroom->github_organization_login).'/'.$this->segment($group->repository_name).'/contents/.capstone-classroom.json';
        $response = $this->installationRequest($classroom)->get($path);

        if ($response->successful()) {
            Log::info('GitHub classroom deployment marker already exists.', [
                'classroom_group_id' => $group->id,
                'github_repository_id' => $group->github_repository_id,
            ]);

            return;
        }

        if (! $response->notFound()) {
            $response->throw();
        }

        $this->installationRequest($classroom)->put($path, [
            'message' => 'Initialize Capstone Classroom deployment',
            'content' => base64_encode(json_encode([
                'classroom' => $classroom->join_code,
                'group' => $group->name,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n"),
        ])->throw();

        Log::info('Created GitHub classroom deployment marker.', [
            'classroom_group_id' => $group->id,
            'github_repository_id' => $group->github_repository_id,
        ]);
    }

    public function hasRepositoryTemplateContents(ClassroomGroup $group): bool
    {
        $classroom = $group->classroom;
        $templatePath = ltrim((string) config('services.github.template_readiness_path'), '/');
        $path = '/repos/'.$this->segment($classroom->github_organization_login).'/'.$this->segment($group->repository_name).'/contents/'.$templatePath;
        $response = $this->installationRequest($classroom)->get($path);

        Log::info('Checked GitHub classroom repository template readiness.', [
            'classroom_group_id' => $group->id,
            'github_repository_id' => $group->github_repository_id,
            'template_path' => $templatePath,
            'status' => $response->status(),
        ]);

        if ($response->notFound()) {
            return false;
        }

        $response->throw();

        return $response->json('type') === 'file';
    }

    public function hasPagesBranch(ClassroomGroup $group): bool
    {
        $response = $this->installationRequest($group->classroom)
            ->get('/repos/'.$this->segment($group->classroom->github_organization_login).'/'.$this->segment($group->repository_name).'/branches/gh-pages');

        if ($response->notFound()) {
            return false;
        }

        $response->throw();

        return true;
    }

    public function configurePages(ClassroomGroup $group): string
    {
        $path = '/repos/'.$this->segment($group->classroom->github_organization_login).'/'.$this->segment($group->repository_name).'/pages';
        $response = $this->installationRequest($group->classroom)->get($path);

        if ($response->notFound()) {
            $response = $this->installationRequest($group->classroom)->post($path, [
                'build_type' => 'legacy',
                'source' => ['branch' => 'gh-pages', 'path' => '/'],
            ]);
        }

        return (string) $response->throw()->json('html_url');
    }

    private function installationRequest(Classroom $classroom): PendingRequest
    {
        if (! $classroom->hasActiveGitHubInstallation()) {
            throw new RuntimeException('The classroom GitHub App installation is not active.');
        }

        $token = Cache::remember(
            'github-installation-token-'.$classroom->github_installation_id,
            now()->addMinutes(50),
            fn (): string => (string) $this->request($this->appJwt())
                ->post('/app/installations/'.$this->segment($classroom->github_installation_id).'/access_tokens')
                ->throw()
                ->json('token'),
        );

        return $this->request($token);
    }

    private function request(string $token): PendingRequest
    {
        return Http::baseUrl((string) config('services.github.api_url'))
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => config('services.github.api_version')])
            ->withToken($token)
            ->connectTimeout(5)
            ->timeout(15);
    }

    private function appJwt(): string
    {
        $privateKey = str_replace('\\n', "\n", (string) config('services.github.private_key'));

        if ($privateKey === '' || config('services.github.app_id') === null) {
            throw new RuntimeException('GitHub App credentials are not configured.');
        }

        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $now = time();
        $payload = $this->base64Url(json_encode([
            'iat' => $now - 60,
            'exp' => $now + 540,
            'iss' => config('services.github.app_id'),
        ], JSON_THROW_ON_ERROR));
        $unsignedToken = $header.'.'.$payload;
        $signed = openssl_sign($unsignedToken, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        if (! $signed) {
            throw new RuntimeException('The GitHub App private key is invalid.');
        }

        return $unsignedToken.'.'.$this->base64Url($signature);
    }

    private function marker(Classroom $classroom): string
    {
        return 'Capstone Classroom '.$classroom->join_code;
    }

    private function segment(string|int|null $value): string
    {
        return rawurlencode((string) $value);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
