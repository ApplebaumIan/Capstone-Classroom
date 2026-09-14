<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GitHubInstallationController extends Controller
{
    public function create(Request $request): SymfonyRedirectResponse
    {
        $user = $request->user();
        $ownsClassroom = $user->classrooms()->exists();
        $isStudentOnly = ! $ownsClassroom
            && ($user->rosterClaims()->exists() || $user->pendingClassrooms()->exists());

        abort_if($isStudentOnly, 403);
        abort_if(blank(config('services.github.app_slug')), 503, 'The GitHub App is not configured.');

        $request->session()->put('github.installation_pending', true);

        if ($request->integer('classroom') !== 0) {
            $classroom = $user->classrooms()->findOrFail($request->integer('classroom'));
            abort_if($classroom->github_organization_id !== null, 409, 'This classroom already has a GitHub organization.');
            $request->session()->put('github.installation_classroom_id', $classroom->id);
        } else {
            $request->session()->forget('github.installation_classroom_id');
        }

        return redirect()->away('https://github.com/apps/'.config('services.github.app_slug').'/installations/new');
    }
}
