<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $firstName = fake()->firstName();
        $lastName = fake()->lastName();

        return [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'full_name' => "{$firstName} {$lastName}",
            'phone' => fake()->unique()->numerify('9########'),
            'email' => fake()->unique()->safeEmail(),
            'source_id' => LeadSource::factory(),
            'status_id' => LeadStatus::factory(),
            'assigned_user_id' => User::factory(),
            'district' => fake()->city(),
            'property_type' => fake()->randomElement(['Departamento', 'Casa', 'Oficina']),
            'budget_min' => fake()->randomFloat(2, 100000, 250000),
            'budget_max' => fake()->randomFloat(2, 260000, 600000),
            'budget_currency' => 'PEN',
            'notes' => fake()->optional()->sentence(),
            'entered_at' => now(),
            'status' => RecordStatus::Active->value,
        ];
    }
}
