<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
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
            'user_id' => User::factory(),
            'reserved_at' => now(),
            'expires_at' => now()->addDays(7),
            'status' => ReservationStatus::Active->value,
            'idempotency_key' => (string) Str::uuid(),
        ];
    }
}
