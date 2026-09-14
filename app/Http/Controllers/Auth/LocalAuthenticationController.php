<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LocalAuthenticationController extends Controller
{
    public function __invoke(Request $request, string $role): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);

        $githubId = match ($role) {
            'teacher' => 'local-teacher',
            'student' => 'local-student',
            'pending-student' => 'sam-rivera',
            default => abort(404),
        };
        $user = User::query()->where('github_id', $githubId)->first();

        abort_if($user === null, 503, 'Local demo users are not seeded. Run php artisan db:seed.');

        Auth::login($user);
        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
