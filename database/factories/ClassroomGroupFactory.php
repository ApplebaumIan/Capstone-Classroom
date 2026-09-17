<?php

namespace Database\Factories;

use App\Enums\GroupStatus;
use App\Models\Classroom;
use App\Models\ClassroomGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassroomGroup>
 */
class ClassroomGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'classroom_id' => Classroom::factory(),
            'name' => 'Section 002: '.fake()->unique()->word(),
            'canvas_group_id' => (string) fake()->unique()->randomNumber(5),
            'canvas_group_reference' => (string) fake()->unique()->randomNumber(5),
            'repository_name' => fake()->unique()->slug(3),
            'status' => GroupStatus::Waiting,
        ];
    }
}
