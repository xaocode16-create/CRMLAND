<?php

use App\Models\Lead;
use App\Models\LeadStatus;
use App\Models\User;
use Database\Seeders\CrmCoreSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(CrmCoreSeeder::class);
});

test('pipeline respects the current role data scope', function () {
    $supervisor = User::factory()->supervisor()->create();
    $teamAdvisor = User::factory()->asesor()->create(['supervisor_user_id' => $supervisor->id]);
    $externalAdvisor = User::factory()->asesor()->create();
    $stage = LeadStatus::query()->where('slug', 'new')->firstOrFail();
    $teamLead = Lead::factory()->create(['assigned_user_id' => $teamAdvisor->id, 'status_id' => $stage->id, 'full_name' => 'Lead visible del equipo']);
    $externalLead = Lead::factory()->create(['assigned_user_id' => $externalAdvisor->id, 'status_id' => $stage->id, 'full_name' => 'Lead fuera del equipo']);

    Livewire::actingAs($supervisor)
        ->test('pages::pipeline.index')
        ->assertSee($teamLead->full_name)
        ->assertDontSee($externalLead->full_name);
});

test('authorized users can move a lead to another pipeline stage', function () {
    $supervisor = User::factory()->supervisor()->create();
    $currentStage = LeadStatus::query()->where('slug', 'new')->firstOrFail();
    $targetStage = LeadStatus::query()->where('slug', 'contacted')->firstOrFail();
    $lead = Lead::factory()->create(['assigned_user_id' => $supervisor->id, 'status_id' => $currentStage->id]);

    Livewire::actingAs($supervisor)
        ->test('pages::pipeline.index')
        ->call('moveLead', $lead->id, $targetStage->id);

    expect($lead->refresh()->status_id)->toBe($targetStage->id);
});
