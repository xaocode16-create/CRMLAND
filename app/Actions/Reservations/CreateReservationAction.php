<?php

namespace App\Actions\Reservations;

use App\Enums\PropertyStatus;
use App\Enums\ReservationStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateReservationAction
{
    /**
     * @param  array{lead_id:int, property_id:int, expires_at:string}  $data
     */
    public function handle(array $data, User $actor): Reservation
    {
        return DB::transaction(function () use ($data, $actor): Reservation {
            /** @var Property $property */
            $property = Property::query()
                ->whereKey($data['property_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($property->status !== PropertyStatus::Available->value) {
                throw new \RuntimeException('La propiedad no esta disponible para reserva.');
            }

            $activeReservationExists = Reservation::query()
                ->where('property_id', $property->getKey())
                ->where('status', ReservationStatus::Active->value)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->exists();

            if ($activeReservationExists) {
                throw new \RuntimeException('La propiedad ya tiene una reserva activa.');
            }

            /** @var Reservation $reservation */
            $reservation = Reservation::query()->create([
                'lead_id' => $data['lead_id'],
                'property_id' => $property->getKey(),
                'user_id' => $actor->getKey(),
                'reserved_at' => now(),
                'expires_at' => $data['expires_at'],
                'status' => ReservationStatus::Active->value,
                'idempotency_key' => (string) Str::uuid(),
            ]);

            $property->forceFill(['status' => PropertyStatus::Reserved->value])->save();

            return $reservation;
        });
    }
}
