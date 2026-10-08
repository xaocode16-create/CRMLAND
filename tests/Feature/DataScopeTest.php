<?php

use App\Models\Lead;
use App\Models\User;
use Database\Seeders\CrmCoreSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(CrmCoreSeeder::class);
});

it('allows administrators to see every assigned lead', function () {
    $admin = User::factory()->administrador()->create();
    $supervisor = User::factory()->supervisor()->create();
    $advisor = User::factory()->asesor()->create(['supervisor_user_id' => $supervisor->id]);
    $externalAdvisor = User::factory()->asesor()->create();

    $ownLead = Lead::factory()->create(['assigned_user_id' => $admin->id]);
    $teamLead = Lead::factory()->create(['assigned_user_id' => $advisor->id]);
    $externalLead = Lead::factory()->create(['assigned_user_id' => $externalAdvisor->id]);

    expect(Lead::query()->visibleFor($admin)->pluck('id')->all())
        ->toEqualCanonicalizing([$ownLead->id, $teamLead->id, $externalLead->id]);

    expect(Gate::forUser($admin)->allows('view', $externalLead))->toBeTrue();
});

it('limits supervisors to their own records and their team records', function () {
    $supervisor = User::factory()->supervisor()->create();
    $teamAdvisor = User::factory()->asesor()->create(['supervisor_user_id' => $supervisor->id]);
    $externalAdvisor = User::factory()->asesor()->create();

    $ownLead = Lead::factory()->create(['assigned_user_id' => $supervisor->id]);
    $teamLead = Lead::factory()->create(['assigned_user_id' => $teamAdvisor->id]);
    $externalLead = Lead::factory()->create(['assigned_user_id' => $externalAdvisor->id]);
    $unassignedLead = Lead::factory()->create(['assigned_user_id' => null]);

    expect(Lead::query()->visibleFor($supervisor)->pluck('id')->all())
        ->toEqualCanonicalizing([$ownLead->id, $teamLead->id]);

    expect(Gate::forUser($supervisor)->allows('view', $ownLead))->toBeTrue();
    expect(Gate::forUser($supervisor)->allows('view', $teamLead))->toBeTrue();
    expect(Gate::forUser($supervisor)->denies('view', $externalLead))->toBeTrue();
    expect(Gate::forUser($supervisor)->denies('view', $unassignedLead))->toBeTrue();
});

it('limits advisors to only their own assigned records', function () {
    $advisor = User::factory()->asesor()->create();
    $otherAdvisor = User::factory()->asesor()->create();

    $ownLead = Lead::factory()->create(['assigned_user_id' => $advisor->id]);
    $otherLead = Lead::factory()->create(['assigned_user_id' => $otherAdvisor->id]);
    $unassignedLead = Lead::factory()->create(['assigned_user_id' => null]);

    expect(Lead::query()->visibleFor($advisor)->pluck('id')->all())
        ->toEqualCanonicalizing([$ownLead->id]);

    expect(Gate::forUser($advisor)->allows('view', $ownLead))->toBeTrue();
    expect(Gate::forUser($advisor)->denies('view', $otherLead))->toBeTrue();
    expect(Gate::forUser($advisor)->denies('view', $unassignedLead))->toBeTrue();
});

it('keeps assignment permissions inside the same data scope', function () {
    $supervisor = User::factory()->supervisor()->create();
    $teamAdvisor = User::factory()->asesor()->create(['supervisor_user_id' => $supervisor->id]);
    $externalAdvisor = User::factory()->asesor()->create();
    $advisor = User::factory()->asesor()->create();

    expect($supervisor->canAssignTo($teamAdvisor->id))->toBeTrue();
    expect($supervisor->canAssignTo($externalAdvisor->id))->toBeFalse();
    expect($advisor->canAssignTo($advisor->id))->toBeTrue();
    expect($advisor->canAssignTo($teamAdvisor->id))->toBeFalse();
});
