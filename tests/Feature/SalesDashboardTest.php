<?php

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\CrmCoreSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(CrmCoreSeeder::class);
});

test('sales respect the supervisor team visibility scope', function () {
    $supervisor = User::factory()->supervisor()->create();
    $teamAdvisor = User::factory()->asesor()->create(['supervisor_user_id' => $supervisor->id]);
    $externalAdvisor = User::factory()->asesor()->create();
    $stage = LeadStatus::query()->where('slug', 'new')->firstOrFail();
    $teamLead = Lead::factory()->create(['assigned_user_id' => $teamAdvisor->id, 'status_id' => $stage->id, 'full_name' => 'Lead de venta del equipo']);
    $externalLead = Lead::factory()->create(['assigned_user_id' => $externalAdvisor->id, 'status_id' => $stage->id, 'full_name' => 'Lead de venta externa']);
    Sale::factory()->create(['lead_id' => $teamLead->id, 'advisor_user_id' => $teamAdvisor->id]);
    Sale::factory()->create(['lead_id' => $externalLead->id, 'advisor_user_id' => $externalAdvisor->id]);

    Livewire::actingAs($supervisor)
        ->test('pages::sales.index')
        ->assertSee($teamLead->full_name)
        ->assertDontSee($externalLead->full_name);
});

test('authorized users can register a sale from the sales dashboard', function () {
    $advisor = User::factory()->asesor()->create();
    $stage = LeadStatus::query()->where('slug', 'new')->firstOrFail();
    $lead = Lead::factory()->create(['assigned_user_id' => $advisor->id, 'status_id' => $stage->id]);

    Livewire::actingAs($advisor)
        ->test('pages::sales.index')
        ->call('create')
        ->set('form', [
            'lead_id' => $lead->id,
            'property_id' => null,
            'advisor_user_id' => $advisor->id,
            'price' => 250000,
            'currency' => 'PEN',
            'sold_at' => now()->format('Y-m-d\TH:i'),
            'commission_percentage' => 3,
            'notes' => 'Venta registrada desde el dashboard',
        ])
        ->call('save');

    $sale = Sale::query()->where('lead_id', $lead->id)->latest('id')->firstOrFail();

    expect($sale->advisor_user_id)->toBe($advisor->id)
        ->and((float) $sale->price)->toBe(250000.0)
        ->and($sale->commission)->not->toBeNull();
});
