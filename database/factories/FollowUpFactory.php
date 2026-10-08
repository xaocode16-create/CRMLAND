<?php

namespace Database\Factories;

use App\Enums\FollowUpPriority;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUp>
 */
class FollowUpFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'assigned_user_id' => User::factory(),
            'type' => FollowUpType::Call->value,
            'scheduled_at' => now()->addDay(),
            'note' => fake()->sentence(),
            'status' => FollowUpStatus::Pending->value,
            'priority' => FollowUpPriority::Normal->value,
        ];
    }
}
