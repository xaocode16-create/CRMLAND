<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
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
            'property_id' => Property::factory(),
            'advisor_user_id' => User::factory(),
            'price' => fake()->randomFloat(2, 150000, 900000),
            'currency' => 'PEN',
            'sold_at' => now(),
            'origin' => 'manual',
            'status' => SaleStatus::Registered->value,
            'notes' => fake()->optional()->sentence(),
            'created_by' => User::factory(),
        ];
    }
}
