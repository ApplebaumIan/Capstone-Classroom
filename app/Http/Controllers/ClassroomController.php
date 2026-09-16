<?php

namespace App\Http\Controllers;

use App\GitHubInstallationStatus;
use App\Models\Classroom;
use App\RepositoryVisibility;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClassroomController extends Controller
{
    public function create(Request $request): Response
    {
        Gate::authorize('create', Classroom::class);

        return $this->formResponse();
    }

    public function store(Request $request, GitHubAppClient $github): RedirectResponse
    {
        Gate::authorize('create', Classroom::class);
        $validated = $this->validateClassroom($request);
        $installation = $this->resolveInstallation($request, $github, $validated['installation_id']);

        $this->ensureOrganizationIsAvailable(
            (string) $installation['account']['id'],
            (string) $installation['id'],
        );

        try {
            $classroom = $request->user()->classrooms()->create([
                'name' => $validated['name'],
                'join_code' => Str::lower(Str::random(32)),
                'repository_visibility' => RepositoryVisibility::Private,
                'github_installation_id' => (string) $installation['id'],
                'github_installation_status' => GitHubInstallationStatus::Active,
                'github_organization_id' => (string) $installation['account']['id'],
                'github_organization_login' => $installation['account']['login'],
            ]);
        } catch (QueryException $exception) {
            if ($this->organizationIsUsed((string) $installation['account']['id'], (string) $installation['id'])) {
                throw ValidationException::withMessages(['installation_id' => 'That GitHub organization already has a classroom.']);
            }

            throw $exception;
        }

        $this->forgetInstallationSession($request);

        return to_route('classrooms.students', $classroom)->with('success', 'Classroom created.');
    }

    public function edit(Request $request, Classroom $classroom): Response
    {
        $this->authorizeOwner($request, $classroom);
        abort_if($classroom->github_organization_id !== null, 404);

        return $this->formResponse($classroom);
    }

    public function update(Request $request, Classroom $classroom, GitHubAppClient $github): RedirectResponse
    {
        $this->authorizeOwner($request, $classroom);
        abort_if($classroom->github_organization_id !== null, 409, 'This classroom already has a GitHub organization.');

        $validated = $this->validateClassroom($request);
        $installation = $this->resolveInstallation($request, $github, $validated['installation_id']);
        $this->ensureOrganizationIsAvailable(
            (string) $installation['account']['id'],
            (string) $installation['id'],
            $classroom,
        );

        try {
            $classroom->update([
                'name' => $validated['name'],
                'github_installation_id' => (string) $installation['id'],
                'github_installation_status' => GitHubInstallationStatus::Active,
                'github_organization_id' => (string) $installation['account']['id'],
                'github_organization_login' => $installation['account']['login'],
            ]);
        } catch (QueryException $exception) {
            if ($this->organizationIsUsed((string) $installation['account']['id'], (string) $installation['id'], $classroom)) {
                throw ValidationException::withMessages(['installation_id' => 'That GitHub organization already has a classroom.']);
            }

            throw $exception;
        }

        $this->forgetInstallationSession($request);

        return to_route('classrooms.students', $classroom)->with('success', 'Classroom setup complete.');
    }

    public function destroy(Request $request, Classroom $classroom): RedirectResponse
    {
        $this->authorizeOwner($request, $classroom);
        $validated = $request->validate([
            'confirmation' => ['required', 'string'],
        ]);

        if (! hash_equals($classroom->name, $validated['confirmation'])) {
            throw ValidationException::withMessages([
                'confirmation' => 'Enter the classroom name exactly to confirm deletion.',
            ]);
        }

        $classroom->delete();

        return to_route('dashboard')->with('success', 'Classroom deleted. GitHub repositories and teams were preserved.');
    }

    private function formResponse(?Classroom $classroom = null): Response
    {
        return Inertia::render('classrooms/create', [
            'classroom' => $classroom === null ? null : [
                'id' => $classroom->id,
                'name' => $classroom->name,
            ],
            'available_installations' => session('github.available_installations', []),
            'github_connected' => is_string(session('github.user_access_token')),
        ]);
    }

    /** @return array{name: string, installation_id: string} */
    private function validateClassroom(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'installation_id' => ['required', 'string'],
        ]);
    }

    /** @return array<string, mixed> */
    private function resolveInstallation(Request $request, GitHubAppClient $github, string $installationId): array
    {
        $encryptedToken = $request->session()->get('github.user_access_token');

        if (! is_string($encryptedToken)) {
            throw ValidationException::withMessages(['installation_id' => 'Reconnect GitHub before selecting an organization.']);
        }

        $installation = collect($github->accessibleInstallations(Crypt::decryptString($encryptedToken)))
            ->first(fn (array $candidate): bool => (string) $candidate['id'] === $installationId);

        if ($installation === null) {
            throw ValidationException::withMessages(['installation_id' => 'That GitHub App installation is not available to your account.']);
        }

        return $installation;
    }

    private function ensureOrganizationIsAvailable(
        string $organizationId,
        string $installationId,
        ?Classroom $classroom = null,
    ): void {
        if ($this->organizationIsUsed($organizationId, $installationId, $classroom)) {
            throw ValidationException::withMessages(['installation_id' => 'That GitHub organization already has a classroom.']);
        }
    }

    private function organizationIsUsed(
        string $organizationId,
        string $installationId,
        ?Classroom $classroom = null,
    ): bool {
        return Classroom::query()
            ->where(function ($query) use ($organizationId, $installationId): void {
                $query->where('github_organization_id', $organizationId)
                    ->orWhere('github_installation_id', $installationId);
            })
            ->when($classroom !== null, fn ($query) => $query->whereKeyNot($classroom->id))
            ->exists();
    }

    private function authorizeOwner(Request $request, Classroom $classroom): void
    {
        abort_unless($classroom->teacher_id === $request->user()->id, 404);
    }

    private function forgetInstallationSession(Request $request): void
    {
        $request->session()->forget([
            'github.installation_pending',
            'github.installation_classroom_id',
            'github.user_access_token',
            'github.available_installations',
        ]);
    }
}
