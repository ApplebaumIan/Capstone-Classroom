<?php

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\ClassroomGroup;
use App\Models\RosterEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RosterEntry>
 */
class RosterEntryFactory extends Factory
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
            'classroom_group_id' => ClassroomGroup::factory(),
            'canvas_user_id' => (string) fake()->unique()->randomNumber(7),
            'canvas_login_id' => fake()->unique()->userName(),
            'canvas_id' => (string) fake()->unique()->randomNumber(7),
            'name' => fake()->name(),
            'sections' => '2026 Fall - CIS-4398-001',
        ];
    }
}
