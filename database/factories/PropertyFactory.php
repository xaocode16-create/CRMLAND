<?php

namespace Database\Factories;

use App\Enums\PropertyOperation;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'PROP-'.fake()->unique()->numberBetween(10000, 99999),
            'title' => fake()->streetName().' '.fake()->randomElement(['Casa', 'Departamento', 'Oficina']),
            'description' => fake()->sentence(),
            'property_type_id' => PropertyType::factory(),
            'operation_type' => PropertyOperation::Sale->value,
            'district' => fake()->city(),
            'address' => fake()->streetAddress(),
            'price' => fake()->randomFloat(2, 120000, 750000),
            'currency' => 'PEN',
            'area_m2' => fake()->randomFloat(2, 60, 240),
            'bedrooms' => fake()->numberBetween(1, 5),
            'bathrooms' => fake()->numberBetween(1, 4),
            'parking_spaces' => fake()->numberBetween(0, 3),
            'status' => PropertyStatus::Available->value,
            'assigned_user_id' => User::factory(),
            'created_by' => User::factory(),
        ];
    }
}
