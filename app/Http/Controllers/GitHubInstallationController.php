<?php

namespace App\Http\Controllers;

use App\Services\GitHub\GitHubAppClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GitHubInstallationController extends Controller
{
    public function create(Request $request): SymfonyRedirectResponse
    {
        abort_unless($request->user()?->classroom !== null, 403);
        abort_if(blank(config('services.github.app_slug')), 503, 'The GitHub App is not configured.');

        $request->session()->put('github.installation_pending', true);

        return redirect()->away('https://github.com/apps/'.config('services.github.app_slug').'/installations/new');
    }

    public function store(Request $request, GitHubAppClient $github): RedirectResponse
    {
        $validated = $request->validate(['installation_id' => ['required', 'string']]);
        $encryptedToken = $request->session()->get('github.user_access_token');

        if (! is_string($encryptedToken)) {
            throw ValidationException::withMessages(['installation_id' => 'Reconnect GitHub before selecting an organization.']);
        }

        $installations = $github->accessibleInstallations(Crypt::decryptString($encryptedToken));
        $installation = collect($installations)->first(
            fn (array $candidate): bool => (string) $candidate['id'] === $validated['installation_id'],
        );

        if ($installation === null) {
            throw ValidationException::withMessages(['installation_id' => 'That GitHub App installation is not available to your account.']);
        }

        $request->user()->classroom->update([
            'github_installation_id' => (string) $installation['id'],
            'github_organization_id' => (string) $installation['account']['id'],
            'github_organization_login' => $installation['account']['login'],
        ]);

        $request->session()->forget([
            'github.installation_pending',
            'github.user_access_token',
            'github.available_installations',
        ]);

        return to_route('dashboard')->with('success', 'GitHub organization connected.');
    }
}
