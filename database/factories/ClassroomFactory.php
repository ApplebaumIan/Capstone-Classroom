<?php

namespace Database\Factories;

use App\GitHubInstallationStatus;
use App\Models\Classroom;
use App\Models\User;
use App\RepositoryVisibility;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Classroom>
 */
class ClassroomFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'teacher_id' => User::factory(),
            'name' => 'CIS 4398 Capstone',
            'join_code' => Str::lower(Str::random(32)),
            'repository_visibility' => RepositoryVisibility::Private,
        ];
    }

    public function installed(): static
    {
        return $this->state(fn (): array => [
            'github_organization_id' => (string) fake()->unique()->randomNumber(8),
            'github_organization_login' => fake()->unique()->userName(),
            'github_installation_id' => (string) fake()->unique()->randomNumber(8),
            'github_installation_status' => GitHubInstallationStatus::Active,
        ]);
    }
}
