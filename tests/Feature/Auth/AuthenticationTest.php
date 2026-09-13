<?php

use App\Models\User;
use Database\Seeders\LocalDevelopmentSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
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
            ->where('claim.name', 'Local Student')
            ->where('claim.group.name', 'Local Demo Team'));
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

    Socialite::shouldReceive('driver->user')->once()->andReturn($githubUser);

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
    Socialite::shouldReceive('driver->user')->once()->andReturn($githubUser);

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
    Socialite::shouldReceive('driver->user')->once()->andReturn($githubUser);

    $response = $this->get(route('github.callback'));

    $response->assertConflict();
    $this->assertGuest();
    expect($user->fresh()->github_id)->toBe('654321');
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
