<?php

use App\Actions\Sales\CreateSaleAction;
use App\Enums\CommissionStatus;
use App\Enums\PropertyStatus;
use App\Enums\ReservationStatus;
use App\Enums\SaleStatus;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\CrmCoreSeeder;
use RuntimeException;

beforeEach(function () {
    $this->seed(CrmCoreSeeder::class);
});

it('creates a sale, commission, property status change and completes matching reservation', function () {
    $advisor = User::factory()->asesor()->create();
    $lead = Lead::factory()->create(['assigned_user_id' => $advisor->id]);
    $property = Property::factory()->create([
        'assigned_user_id' => $advisor->id,
        'status' => PropertyStatus::Reserved->value,
        'price' => 200000,
    ]);
    $reservation = Reservation::factory()->create([
        'lead_id' => $lead->id,
        'property_id' => $property->id,
        'user_id' => $advisor->id,
        'status' => ReservationStatus::Active->value,
    ]);

    $sale = app(CreateSaleAction::class)->handle([
        'lead_id' => $lead->id,
        'property_id' => $property->id,
        'advisor_user_id' => $advisor->id,
        'price' => 200000,
        'currency' => 'PEN',
        'sold_at' => now()->toDateTimeString(),
        'commission_percentage' => 3,
    ], $advisor);

    expect($sale->status)->toBe(SaleStatus::Registered->value);
    expect($property->fresh()->status)->toBe(PropertyStatus::Sold->value);
    expect($reservation->fresh()->status)->toBe(ReservationStatus::Completed->value);
    $this->assertDatabaseHas('commissions', [
        'sale_id' => $sale->id,
        'advisor_user_id' => $advisor->id,
        'status' => CommissionStatus::Pending->value,
        'amount' => 6000,
    ]);
});

it('rejects sales with no positive price', function () {
    $advisor = User::factory()->asesor()->create();
    $lead = Lead::factory()->create(['assigned_user_id' => $advisor->id]);
    $property = Property::factory()->create([
        'assigned_user_id' => $advisor->id,
        'status' => PropertyStatus::Available->value,
        'price' => 0,
    ]);

    expect(fn () => app(CreateSaleAction::class)->handle([
        'lead_id' => $lead->id,
        'property_id' => $property->id,
        'advisor_user_id' => $advisor->id,
        'price' => 0,
        'currency' => 'PEN',
        'sold_at' => now()->toDateTimeString(),
        'commission_percentage' => 3,
    ], $advisor))->toThrow(RuntimeException::class, 'La venta requiere un precio mayor a cero.');

    expect(Sale::query()->count())->toBe(0);
});

it('rejects a duplicated active sale for the same property', function () {
    $advisor = User::factory()->asesor()->create();
    $lead = Lead::factory()->create(['assigned_user_id' => $advisor->id]);
    $property = Property::factory()->create([
        'assigned_user_id' => $advisor->id,
        'status' => PropertyStatus::Available->value,
    ]);
    Sale::factory()->create([
        'property_id' => $property->id,
        'advisor_user_id' => $advisor->id,
        'status' => SaleStatus::Registered->value,
    ]);

    expect(fn () => app(CreateSaleAction::class)->handle([
        'lead_id' => $lead->id,
        'property_id' => $property->id,
        'advisor_user_id' => $advisor->id,
        'price' => 180000,
        'currency' => 'PEN',
        'sold_at' => now()->toDateTimeString(),
        'commission_percentage' => 3,
    ], $advisor))->toThrow(RuntimeException::class, 'La propiedad ya tiene una venta activa.');

    expect(Sale::query()->where('property_id', $property->id)->count())->toBe(1);
});
