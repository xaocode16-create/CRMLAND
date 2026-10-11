<?php

use App\Actions\Reservations\CreateReservationAction;
use App\Enums\PropertyStatus;
use App\Enums\ReservationStatus;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\CrmCoreSeeder;
use RuntimeException;

beforeEach(function () {
    $this->seed(CrmCoreSeeder::class);
});

it('creates an active reservation and reserves the property', function () {
    $advisor = User::factory()->asesor()->create();
    $lead = Lead::factory()->create(['assigned_user_id' => $advisor->id]);
    $property = Property::factory()->create([
        'assigned_user_id' => $advisor->id,
        'status' => PropertyStatus::Available->value,
    ]);

    $reservation = app(CreateReservationAction::class)->handle([
        'lead_id' => $lead->id,
        'property_id' => $property->id,
        'expires_at' => now()->addDays(5)->toDateTimeString(),
    ], $advisor);

    expect($reservation->status)->toBe(ReservationStatus::Active->value);
    expect($reservation->approval_status)->toBe('PENDING');
    expect($reservation->user_id)->toBe($advisor->id);
    expect($property->fresh()->status)->toBe(PropertyStatus::Reserved->value);
    $this->assertDatabaseHas('reservations', [
        'lead_id' => $lead->id,
        'property_id' => $property->id,
        'status' => ReservationStatus::Active->value,
    ]);
});

it('rejects a reservation when the property is not available', function () {
    $advisor = User::factory()->asesor()->create();
    $lead = Lead::factory()->create(['assigned_user_id' => $advisor->id]);
    $property = Property::factory()->create([
        'assigned_user_id' => $advisor->id,
        'status' => PropertyStatus::Sold->value,
    ]);

    expect(fn () => app(CreateReservationAction::class)->handle([
        'lead_id' => $lead->id,
        'property_id' => $property->id,
        'expires_at' => now()->addDays(5)->toDateTimeString(),
    ], $advisor))->toThrow(RuntimeException::class, 'La propiedad no esta disponible para reserva.');

    $this->assertDatabaseMissing('reservations', [
        'lead_id' => $lead->id,
        'property_id' => $property->id,
    ]);
});

it('rejects a duplicated active reservation for the same property', function () {
    $advisor = User::factory()->asesor()->create();
    $lead = Lead::factory()->create(['assigned_user_id' => $advisor->id]);
    $otherLead = Lead::factory()->create(['assigned_user_id' => $advisor->id]);
    $property = Property::factory()->create([
        'assigned_user_id' => $advisor->id,
        'status' => PropertyStatus::Available->value,
    ]);
    Reservation::factory()->create([
        'lead_id' => $otherLead->id,
        'property_id' => $property->id,
        'user_id' => $advisor->id,
        'status' => ReservationStatus::Active->value,
        'expires_at' => now()->addDays(3),
    ]);

    expect(fn () => app(CreateReservationAction::class)->handle([
        'lead_id' => $lead->id,
        'property_id' => $property->id,
        'expires_at' => now()->addDays(5)->toDateTimeString(),
    ], $advisor))->toThrow(RuntimeException::class, 'La propiedad ya tiene una reserva activa.');

    expect(Reservation::query()->where('property_id', $property->id)->count())->toBe(1);
});
