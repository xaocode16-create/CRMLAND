<?php

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use Database\Seeders\CrmCoreSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(CrmCoreSeeder::class);
});

test('calendar respects the supervisor team visibility scope', function () {
    $supervisor = User::factory()->supervisor()->create();
    $teamAdvisor = User::factory()->asesor()->create(['supervisor_user_id' => $supervisor->id]);
    $externalAdvisor = User::factory()->asesor()->create();
    $stage = LeadStatus::query()->where('slug', 'new')->firstOrFail();
    $teamLead = Lead::factory()->create(['assigned_user_id' => $teamAdvisor->id, 'status_id' => $stage->id, 'full_name' => 'Lead visible en calendario']);
    $externalLead = Lead::factory()->create(['assigned_user_id' => $externalAdvisor->id, 'status_id' => $stage->id, 'full_name' => 'Lead fuera del equipo']);
    FollowUp::factory()->create(['lead_id' => $teamLead->id, 'assigned_user_id' => $teamAdvisor->id]);
    FollowUp::factory()->create(['lead_id' => $externalLead->id, 'assigned_user_id' => $externalAdvisor->id]);

    Livewire::actingAs($supervisor)
        ->test('pages::calendar.index')
        ->assertSee($teamLead->full_name)
        ->assertDontSee($externalLead->full_name);
});

test('authorized users can complete a calendar activity', function () {
    $advisor = User::factory()->asesor()->create();
    $stage = LeadStatus::query()->where('slug', 'new')->firstOrFail();
    $lead = Lead::factory()->create(['assigned_user_id' => $advisor->id, 'status_id' => $stage->id]);
    $followUp = FollowUp::factory()->create(['lead_id' => $lead->id, 'assigned_user_id' => $advisor->id]);

    Livewire::actingAs($advisor)
        ->test('pages::calendar.index')
        ->call('complete', $followUp->id);

    expect($followUp->refresh()->status)->toBe(FollowUpStatus::Completed->value);
});
