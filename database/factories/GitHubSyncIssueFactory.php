<?php

namespace Database\Factories;

use App\Enums\GitHubSyncIssueType;
use App\Models\ClassroomGroup;
use App\Models\GitHubSyncIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitHubSyncIssue>
 */
class GitHubSyncIssueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'classroom_group_id' => ClassroomGroup::factory(),
            'delivery_id' => fake()->uuid(),
            'type' => GitHubSyncIssueType::MembershipRemoved,
            'detected_at' => now(),
        ];
    }
}
