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
    public function redirect(): RedirectResponse|SymfonyRedirectResponse
    {
        return Socialite::driver('github')->redirect();
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
            $request->session()->forget('github.installation_pending');

            return Auth::check()
                ? to_route('dashboard')->withErrors(['github' => 'GitHub authorization was cancelled.'])
                : to_route('login')->withErrors(['github' => 'GitHub authorization was cancelled.']);
        }

        $provider = Socialite::driver('github');

        if ($installationPending) {
            abort_unless(method_exists($provider, 'stateless'), 500);
            $provider->stateless();
        }

        $githubUser = $provider->user();
        $email = $githubUser->getEmail();

        abort_if($email === null, 422, 'Your GitHub account must have an email address.');

        $user = User::query()->where('github_id', $githubUser->getId())->first();

        if ($installationPending && Auth::check() && Auth::id() !== $user?->id) {
            abort(403, 'Install the GitHub App with the same account you used to sign in.');
        }

        $user ??= User::query()->where('email', $email)->firstOrNew();

        abort_if(
            $user->github_id !== null && $user->github_id !== $githubUser->getId(),
            409,
            'That email address is already linked to another GitHub account.',
        );

        $user->forceFill([
            'github_id' => $githubUser->getId(),
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
                'login' => $installation['account']['login'],
                'avatar_url' => $installation['account']['avatar_url'] ?? null,
            ])->values()->all());

            return to_route('dashboard');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
