<?php

namespace Database\Factories;

use App\Enums\CommissionStatus;
use App\Models\Commission;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Commission>
 */
class CommissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 3000, 15000);

        return [
            'sale_id' => Sale::factory(),
            'advisor_user_id' => User::factory(),
            'percentage' => 3,
            'amount' => $amount,
            'currency' => 'PEN',
            'status' => CommissionStatus::Pending->value,
        ];
    }
}
