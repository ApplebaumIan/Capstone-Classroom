<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'teacherNavigation' => function () use ($request): array {
                $user = $request->user();

                if ($user === null) {
                    return ['classrooms' => [], 'can_create_classroom' => false];
                }

                $classrooms = $user->classrooms()
                    ->orderBy('name')
                    ->get(['id', 'name', 'github_organization_login']);
                $isStudentOnly = $classrooms->isEmpty()
                    && ($user->rosterClaims()->exists() || $user->pendingClassrooms()->exists());

                return [
                    'classrooms' => $classrooms->map(fn ($classroom): array => [
                        'id' => $classroom->id,
                        'name' => $classroom->name,
                        'organization' => $classroom->github_organization_login,
                    ]),
                    'can_create_classroom' => ! $isStudentOnly,
                ];
            },
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
