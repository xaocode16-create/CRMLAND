<?php

namespace Database\Factories;

use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\PropertyUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyUnit>
 */
class PropertyUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'unit_number' => (string) fake()->unique()->numberBetween(100, 999),
            'unit_type' => fake()->randomElement(['Flat', 'Duplex', 'Loft']),
            'floor' => (string) fake()->numberBetween(1, 20),
            'area_m2' => fake()->randomFloat(2, 45, 160),
            'bedrooms' => fake()->numberBetween(1, 4),
            'bathrooms' => fake()->numberBetween(1, 3),
            'parking_spaces' => fake()->numberBetween(0, 2),
            'price' => fake()->randomFloat(2, 120000, 600000),
            'currency' => 'PEN',
            'status' => PropertyStatus::Available->value,
        ];
    }
}
