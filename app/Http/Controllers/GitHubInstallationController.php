<?php

namespace App\Http\Controllers;

use App\Services\GitHub\GitHubAppClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GitHubInstallationController extends Controller
{
    public function create(Request $request): RedirectResponse|SymfonyRedirectResponse
    {
        $this->prepareInstallation($request);
        $provider = Socialite::driver('github');
        abort_unless(method_exists($provider, 'redirectUrl'), 500);

        return $provider
            ->redirectUrl($request->root().route('github.callback', absolute: false))
            ->redirect();
    }

    public function edit(Request $request): SymfonyRedirectResponse
    {
        $this->authorizeTeacherAccount($request);
        abort_if(blank(config('services.github.app_slug')), 503, 'The GitHub App is not configured.');

        return redirect()->away('https://github.com/apps/'.config('services.github.app_slug').'/installations/new');
    }

    public function store(Request $request, GitHubAppClient $github): RedirectResponse
    {
        $this->authorizeTeacherAccount($request);
        $encryptedToken = $request->session()->get('github.user_access_token');

        if (! is_string($encryptedToken)) {
            throw ValidationException::withMessages(['github' => 'Reconnect GitHub before refreshing organizations.']);
        }

        $installations = $github->accessibleInstallations(Crypt::decryptString($encryptedToken));

        $request->session()->put('github.available_installations', collect($installations)->map(fn (array $installation): array => [
            'id' => (string) $installation['id'],
            'account_id' => (string) $installation['account']['id'],
            'login' => $installation['account']['login'],
            'avatar_url' => $installation['account']['avatar_url'] ?? null,
        ])->values()->all());

        return redirect()->to($this->installationReturnUrl($request));
    }

    private function prepareInstallation(Request $request): void
    {
        $this->authorizeTeacherAccount($request);
        $request->session()->put('github.installation_pending', true);

        if ($request->integer('classroom') === 0) {
            $request->session()->forget('github.installation_classroom_id');

            return;
        }

        $classroom = $request->user()->classrooms()->findOrFail($request->integer('classroom'));
        abort_if($classroom->github_organization_id !== null, 409, 'This classroom already has a GitHub organization.');
        $request->session()->put('github.installation_classroom_id', $classroom->id);
    }

    private function authorizeTeacherAccount(Request $request): void
    {
        $user = $request->user();
        $ownsClassroom = $user->classrooms()->exists();
        $isStudentOnly = ! $ownsClassroom
            && ($user->rosterClaims()->exists() || $user->pendingClassrooms()->exists());

        abort_if($isStudentOnly, 403);
    }

    private function installationReturnUrl(Request $request): string
    {
        $classroomId = $request->session()->get('github.installation_classroom_id');

        return is_int($classroomId)
            ? route('classrooms.edit', $classroomId, absolute: false)
            : route('classrooms.create', absolute: false);
    }
}
