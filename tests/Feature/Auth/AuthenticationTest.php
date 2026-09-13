<?php

use App\Models\User;
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
            ->missing('canResetPassword'));
});

test('users can authenticate with github', function () {
    $githubUser = (new GitHubUser)->map([
        'id' => '123456',
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
