<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\PropertyType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PropertyType>
 */
class PropertyTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Casa', 'Departamento', 'Terreno', 'Oficina', 'Local Comercial'])
            .'-'.fake()->unique()->numberBetween(100000, 999999);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'status' => RecordStatus::Active->value,
        ];
    }
}
