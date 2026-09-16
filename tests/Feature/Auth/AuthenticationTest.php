<?php

use App\Models\User;
use Database\Seeders\LocalDevelopmentSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\User as GitHubUser;

uses(RefreshDatabase::class);

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('localAuthEnabled', false)
            ->missing('canResetPassword'));
});

test('local login choices are exposed in the local environment', function () {
    $this->app['env'] = 'local';

    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('localAuthEnabled', true));
});

test('local teacher bypass authenticates into the teacher dashboard', function () {
    $this->app['env'] = 'local';
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->seed(LocalDevelopmentSeeder::class);

    $response = $this->post(route('local.login', 'teacher'));

    $response->assertRedirect(route('dashboard'));
    $teacher = User::query()->where('email', 'teacher@capstone.local')->firstOrFail();
    $this->assertAuthenticatedAs($teacher);
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('mode', 'teacher'));
});

test('local student bypass authenticates into the student dashboard', function () {
    $this->app['env'] = 'local';
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->seed(LocalDevelopmentSeeder::class);

    $response = $this->post(route('local.login', 'student'));

    $response->assertRedirect(route('dashboard'));
    $student = User::query()->where('email', 'student@capstone.local')->firstOrFail();
    $this->assertAuthenticatedAs($student);
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('mode', 'student')
            ->where('claim.name', 'Demo Student')
            ->where('claim.group.name', 'Local Demo Team'));
});

test('local unlinked student bypass opens roster selection', function () {
    $this->app['env'] = 'local';
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->seed(LocalDevelopmentSeeder::class);

    $response = $this->post(route('local.login', 'pending-student'));

    $response->assertRedirect(route('dashboard'));
    $student = User::query()->where('email', 'pending-student@capstone.local')->firstOrFail();
    $this->assertAuthenticatedAs($student);
    $this->get(route('dashboard'))
        ->assertRedirect(route('classrooms.join', 'local-capstone-classroom'));
});

test('local authentication bypass is unavailable outside the local environment', function () {
    $this->post(route('local.login', 'teacher'))->assertNotFound();

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('users can authenticate with github', function () {
    $githubUser = (new GitHubUser)->map([
        'id' => 123456,
        'nickname' => 'octocat',
        'name' => 'The Octocat',
        'email' => 'octocat@github.com',
        'avatar' => null,
    ]);

    $provider = Mockery::mock(GithubProvider::class);
    $provider->shouldReceive('redirectUrl')->once()->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($githubUser);
    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

    $response = $this->get(route('github.callback'));

    $user = User::query()->where('github_id', '123456')->firstOrFail();

    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('The Octocat')
        ->and($user->email)->toBe('octocat@github.com')
        ->and($user->email_verified_at)->not->toBeNull();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('existing users can authenticate when github returns their id as an integer', function () {
    $user = User::factory()->create([
        'github_id' => '123456',
        'github_login' => 'octocat',
        'email' => 'octocat@github.com',
    ]);
    $githubUser = (new GitHubUser)->map([
        'id' => 123456,
        'nickname' => 'octocat',
        'name' => 'The Octocat',
        'email' => 'octocat@github.com',
        'avatar' => null,
    ]);
    $provider = Mockery::mock(GithubProvider::class);
    $provider->shouldReceive('redirectUrl')->once()->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($githubUser);
    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

    $response = $this->get(route('github.callback'));

    $response->assertRedirect(route('dashboard', absolute: false));
    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
});

test('an email linked to a different github account is rejected', function () {
    $user = User::factory()->create([
        'github_id' => '654321',
        'email' => 'octocat@github.com',
    ]);
    $githubUser = (new GitHubUser)->map([
        'id' => 123456,
        'nickname' => 'octocat',
        'name' => 'The Octocat',
        'email' => 'octocat@github.com',
        'avatar' => null,
    ]);
    $provider = Mockery::mock(GithubProvider::class);
    $provider->shouldReceive('redirectUrl')->once()->andReturnSelf();
    $provider->shouldReceive('user')->once()->andReturn($githubUser);
    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

    $response = $this->get(route('github.callback'));

    $response->assertConflict();
    $this->assertGuest();
    expect($user->fresh()->github_id)->toBe('654321');
});

test('github authorization uses the current preview callback URL', function () {
    $provider = Mockery::mock(GithubProvider::class);
    $provider->shouldReceive('redirectUrl')
        ->once()
        ->with('https://pr-123.preview.example.com/auth/github/callback')
        ->andReturnSelf();
    $provider->shouldReceive('redirect')
        ->once()
        ->andReturn(redirect()->away('https://github.com/login/oauth/authorize'));
    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

    $response = $this->get('https://pr-123.preview.example.com/auth/github');

    $response->assertRedirect('https://github.com/login/oauth/authorize');
});

test('github setup callback stores accessible organizations without disabling oauth state', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.github.com/user/installations*' => Http::response([
            'installations' => [[
                'id' => 123,
                'target_type' => 'Organization',
                'account' => ['id' => 456, 'login' => 'temple'],
            ]],
        ]),
    ]);
    $teacher = User::factory()->create([
        'github_id' => '123456',
        'email' => 'octocat@github.com',
    ]);
    $githubUser = (new GitHubUser)->map([
        'id' => 123456,
        'nickname' => 'octocat',
        'name' => 'The Octocat',
        'email' => 'octocat@github.com',
        'avatar' => null,
    ]);
    $githubUser->token = 'user-token';
    $provider = Mockery::mock(GithubProvider::class);
    $provider->shouldReceive('redirectUrl')->once()->andReturnSelf();
    $provider->shouldReceive('stateless')->never();
    $provider->shouldReceive('user')->once()->andReturn($githubUser);
    Socialite::shouldReceive('driver')->once()->with('github')->andReturn($provider);

    $response = $this->actingAs($teacher)
        ->withSession(['github.installation_pending' => true])
        ->get(route('github.callback'));

    $response->assertRedirect(route('classrooms.create', absolute: false))
        ->assertSessionHas('github.available_installations', [[
            'id' => '123',
            'account_id' => '456',
            'login' => 'temple',
            'avatar_url' => null,
        ]]);
    expect(Crypt::decryptString(session('github.user_access_token')))->toBe('user-token');
});

test('users cannot authenticate with a password', function () {
    $user = User::factory()->create();

    $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertMethodNotAllowed();

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});
