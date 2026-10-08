<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $module = fake()->randomElement(['Leads', 'Propiedades', 'Usuarios', 'Reportes']);
        $name = fake()->unique()->lexify('???? ???? ????');

        return [
            'name' => Str::headline($name),
            'slug' => Str::slug($module).'.'.Str::slug($name),
            'description' => fake()->sentence(),
            'module' => $module,
        ];
    }
}
