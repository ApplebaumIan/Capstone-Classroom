<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GitHub\GitHubAppClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GitHubAuthenticationController extends Controller
{
    public function redirect(Request $request): RedirectResponse|SymfonyRedirectResponse
    {
        $provider = Socialite::driver('github');
        abort_unless(method_exists($provider, 'redirectUrl'), 500);

        return $provider
            ->redirectUrl($request->root().route('github.callback', absolute: false))
            ->redirect();
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('home');
    }

    public function callback(Request $request, GitHubAppClient $github): RedirectResponse
    {
        $installationPending = $request->session()->get('github.installation_pending') === true;

        if ($request->filled('error')) {
            $returnUrl = $this->installationReturnUrl($request);
            $request->session()->forget([
                'github.installation_pending',
                'github.installation_classroom_id',
            ]);

            return Auth::check()
                ? redirect()->to($returnUrl)->withErrors(['github' => 'GitHub authorization was cancelled.'])
                : to_route('login')->withErrors(['github' => 'GitHub authorization was cancelled.']);
        }

        $provider = Socialite::driver('github');
        abort_unless(method_exists($provider, 'redirectUrl'), 500);

        $githubUser = $provider
            ->redirectUrl($request->root().route('github.callback', absolute: false))
            ->user();
        $githubId = (string) $githubUser->getId();
        $email = $githubUser->getEmail();

        abort_if($email === null, 422, 'Your GitHub account must have an email address.');

        $user = User::query()->where('github_id', $githubId)->first();
        $user ??= User::query()->where('email', $email)->first();

        if ($installationPending && Auth::check() && Auth::id() !== $user?->id) {
            abort(403, 'Install the GitHub App with the same account you used to sign in.');
        }

        $user ??= new User;

        abort_if(
            $user->github_id !== null && $user->github_id !== $githubId,
            409,
            'That email address is already linked to another GitHub account.',
        );

        $user->forceFill([
            'github_id' => $githubId,
            'github_login' => $githubUser->getNickname(),
            'avatar_url' => $githubUser->getAvatar(),
            'name' => $githubUser->getName() ?: $githubUser->getNickname() ?: $email,
            'email' => $email,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'password' => $user->password ?: Str::password(32),
        ])->save();

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        if ($installationPending) {
            abort_unless($githubUser instanceof SocialiteUser, 500);
            $installations = $github->accessibleInstallations($githubUser->token);

            $request->session()->put('github.user_access_token', Crypt::encryptString($githubUser->token));
            $request->session()->put('github.available_installations', collect($installations)->map(fn (array $installation): array => [
                'id' => (string) $installation['id'],
                'account_id' => (string) $installation['account']['id'],
                'login' => $installation['account']['login'],
                'avatar_url' => $installation['account']['avatar_url'] ?? null,
            ])->values()->all());

            return redirect()->to($this->installationReturnUrl($request));
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function installationReturnUrl(Request $request): string
    {
        $classroomId = $request->session()->get('github.installation_classroom_id');

        return is_int($classroomId)
            ? route('classrooms.edit', $classroomId, absolute: false)
            : route('classrooms.create', absolute: false);
    }
}
