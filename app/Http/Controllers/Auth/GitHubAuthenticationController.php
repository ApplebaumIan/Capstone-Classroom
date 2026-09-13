<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GitHubAuthenticationController extends Controller
{
    public function redirect(): RedirectResponse|SymfonyRedirectResponse
    {
        return Socialite::driver('github')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $githubUser = Socialite::driver('github')->user();
        $email = $githubUser->getEmail();

        abort_if($email === null, 422, 'Your GitHub account must have an email address.');

        $user = User::query()->firstOrNew(['github_id' => $githubUser->getId()]);

        if (! $user->exists) {
            $user = User::query()->firstOrNew(['email' => $email]);
        }

        $user->forceFill([
            'github_id' => $githubUser->getId(),
            'name' => $githubUser->getName() ?: $githubUser->getNickname() ?: $email,
            'email' => $email,
            'email_verified_at' => $user->email_verified_at ?? now(),
            'password' => $user->password ?: Str::password(32),
        ])->save();

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
