<?php

use App\Models\Lead;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\CrmCoreSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(CrmCoreSeeder::class);
});

test('opens the complete reservation form over the dashboard', function () {
    $admin = User::factory()->administrador()->create();
    $this->actingAs($admin);

    Livewire::test('pages::reservations.index')
        ->assertSee('Gestion de reservas')
        ->assertSee('crm-reservation-side-action', false)
        ->call('showPendingApprovals')
        ->assertSet('status', 'ACTIVE')
        ->assertSet('approvalStatus', 'PENDING')
        ->call('toggleAllActivity')
        ->assertSet('showAllActivity', true)
        ->call('create')
        ->assertSet('showForm', true)
        ->assertSee('crm-reservation-modal', false)
        ->assertSee('Documentacion adjunta')
        ->assertSee('Resumen de la reserva');
});

test('updates the reservation summary as form values change', function () {
    $admin = User::factory()->administrador()->create();
    $lead = Lead::factory()->create();
    $property = Property::factory()->create();
    $this->actingAs($admin);

    Livewire::test('pages::reservations.index')
        ->call('create')
        ->set('form.lead_id', $lead->id)
        ->set('form.property_id', $property->id)
        ->set('form.expires_at', '2026-10-20T16:30')
        ->set('form.separation_amount', 8500)
        ->set('form.currency', 'USD')
        ->set('form.priority', 'HIGH')
        ->assertSee($lead->full_name)
        ->assertSee($property->title)
        ->assertSee('USD 8,500.00')
        ->assertSee('20/10/2026 16:30')
        ->assertSee('Alta');
});

test('saves reservation workflow details and attached documents', function () {
    $admin = User::factory()->administrador()->create();
    $advisor = User::factory()->asesor()->create();
    $lead = Lead::factory()->create(['assigned_user_id' => $advisor->id]);
    $property = Property::factory()->create(['assigned_user_id' => $advisor->id]);
    $document = UploadedFile::fake()->create('comprobante.pdf', 120, 'application/pdf');
    Storage::fake('public');
    $this->actingAs($admin);

    Livewire::test('pages::reservations.index')
        ->call('create')
        ->set('form.lead_id', $lead->id)
        ->set('form.property_id', $property->id)
        ->set('form.advisor_user_id', $advisor->id)
        ->set('form.separation_amount', 5000)
        ->set('form.payment_status', 'CONFIRMED')
        ->set('form.payment_method', 'TRANSFER')
        ->set('documents', [$document])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $reservation = Reservation::query()->where('lead_id', $lead->id)->firstOrFail();

    expect($reservation->code)->toStartWith('RES-')
        ->and($reservation->separation_amount)->toBe('5000.00')
        ->and($reservation->payment_status)->toBe('CONFIRMED')
        ->and($reservation->documents_json)->toHaveCount(1);
    Storage::disk('public')->assertExists($reservation->documents_json[0]['storage_key']);
});

test('limits approval to administrators and supervisors in the reservation policy', function () {
    $admin = User::factory()->administrador()->create();
    $supervisor = User::factory()->supervisor()->create();
    $teamAdvisor = User::factory()->asesor()->create(['supervisor_user_id' => $supervisor->id]);
    $advisor = User::factory()->asesor()->create();
    $teamReservation = Reservation::factory()->create(['user_id' => $teamAdvisor->id]);
    $ownReservation = Reservation::factory()->create(['user_id' => $advisor->id]);

    expect(Gate::forUser($admin)->allows('approve', $teamReservation))->toBeTrue()
        ->and(Gate::forUser($supervisor)->allows('approve', $teamReservation))->toBeTrue()
        ->and(Gate::forUser($advisor)->denies('approve', $ownReservation))->toBeTrue();
});
