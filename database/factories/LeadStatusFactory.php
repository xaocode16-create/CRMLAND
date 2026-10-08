<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\LeadStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LeadStatus>
 */
class LeadStatusFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Nuevo', 'Contactado', 'En seguimiento', 'Cita programada', 'Negociacion'])
            .'-'.fake()->unique()->numberBetween(100000, 999999);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'color' => fake()->hexColor(),
            'sort_order' => fake()->numberBetween(1, 20),
            'status' => RecordStatus::Active->value,
        ];
    }
}
