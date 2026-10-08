<?php

use App\Models\Commission;
use App\Models\FollowUp;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\CrmCoreSeeder;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->seed(CrmCoreSeeder::class);
});

it('allows administrators to see every operational record', function () {
    $admin = User::factory()->administrador()->create();
    $advisor = User::factory()->asesor()->create();
    $externalAdvisor = User::factory()->asesor()->create();

    $teamProperty = Property::factory()->create(['assigned_user_id' => $advisor->id]);
    $externalProperty = Property::factory()->create(['assigned_user_id' => $externalAdvisor->id]);
    $teamFollowUp = FollowUp::factory()->create(['assigned_user_id' => $advisor->id]);
    $externalFollowUp = FollowUp::factory()->create(['assigned_user_id' => $externalAdvisor->id]);
    $teamSale = Sale::factory()->create(['advisor_user_id' => $advisor->id]);
    $externalSale = Sale::factory()->create(['advisor_user_id' => $externalAdvisor->id]);
    $teamReservation = Reservation::factory()->create(['user_id' => $advisor->id]);
    $externalReservation = Reservation::factory()->create(['user_id' => $externalAdvisor->id]);
    $teamCommission = Commission::factory()->create(['advisor_user_id' => $advisor->id]);
    $externalCommission = Commission::factory()->create(['advisor_user_id' => $externalAdvisor->id]);

    expect(Property::query()->visibleFor($admin)->pluck('id')->all())
        ->toContain($teamProperty->id)
        ->toContain($externalProperty->id);
    expect(FollowUp::query()->visibleFor($admin)->pluck('id')->all())->toEqualCanonicalizing([$teamFollowUp->id, $externalFollowUp->id]);
    expect(Sale::query()->visibleFor($admin)->pluck('id')->all())
        ->toContain($teamSale->id)
        ->toContain($externalSale->id);
    expect(Reservation::query()->visibleFor($admin)->pluck('id')->all())->toEqualCanonicalizing([$teamReservation->id, $externalReservation->id]);
    expect(Commission::query()->visibleFor($admin)->pluck('id')->all())->toEqualCanonicalizing([$teamCommission->id, $externalCommission->id]);

    expect(Gate::forUser($admin)->allows('view', $externalProperty))->toBeTrue();
    expect(Gate::forUser($admin)->allows('view', $externalFollowUp))->toBeTrue();
    expect(Gate::forUser($admin)->allows('view', $externalSale))->toBeTrue();
    expect(Gate::forUser($admin)->allows('view', $externalReservation))->toBeTrue();
    expect(Gate::forUser($admin)->allows('view', $externalCommission))->toBeTrue();
});

it('limits supervisors to their team operational records', function () {
    $supervisor = User::factory()->supervisor()->create();
    $teamAdvisor = User::factory()->asesor()->create(['supervisor_user_id' => $supervisor->id]);
    $externalAdvisor = User::factory()->asesor()->create();

    $ownProperty = Property::factory()->create(['assigned_user_id' => $supervisor->id]);
    $teamProperty = Property::factory()->create(['assigned_user_id' => $teamAdvisor->id]);
    $externalProperty = Property::factory()->create(['assigned_user_id' => $externalAdvisor->id]);
    $teamFollowUp = FollowUp::factory()->create(['assigned_user_id' => $teamAdvisor->id]);
    $externalFollowUp = FollowUp::factory()->create(['assigned_user_id' => $externalAdvisor->id]);
    $teamSale = Sale::factory()->create(['advisor_user_id' => $teamAdvisor->id]);
    $externalSale = Sale::factory()->create(['advisor_user_id' => $externalAdvisor->id]);
    $teamReservation = Reservation::factory()->create(['user_id' => $teamAdvisor->id]);
    $externalReservation = Reservation::factory()->create(['user_id' => $externalAdvisor->id]);
    $teamCommission = Commission::factory()->create(['advisor_user_id' => $teamAdvisor->id]);
    $externalCommission = Commission::factory()->create(['advisor_user_id' => $externalAdvisor->id]);

    expect(Property::query()->visibleFor($supervisor)->pluck('id')->all())->toEqualCanonicalizing([$ownProperty->id, $teamProperty->id]);
    expect(FollowUp::query()->visibleFor($supervisor)->pluck('id')->all())->toEqualCanonicalizing([$teamFollowUp->id]);
    expect(Sale::query()->visibleFor($supervisor)->pluck('id')->all())->toEqualCanonicalizing([$teamSale->id]);
    expect(Reservation::query()->visibleFor($supervisor)->pluck('id')->all())->toEqualCanonicalizing([$teamReservation->id]);
    expect(Commission::query()->visibleFor($supervisor)->pluck('id')->all())->toEqualCanonicalizing([$teamCommission->id]);

    expect(Gate::forUser($supervisor)->denies('view', $externalProperty))->toBeTrue();
    expect(Gate::forUser($supervisor)->denies('view', $externalFollowUp))->toBeTrue();
    expect(Gate::forUser($supervisor)->denies('view', $externalSale))->toBeTrue();
    expect(Gate::forUser($supervisor)->denies('view', $externalReservation))->toBeTrue();
    expect(Gate::forUser($supervisor)->denies('view', $externalCommission))->toBeTrue();
});

it('limits advisors to their own operational records', function () {
    $advisor = User::factory()->asesor()->create();
    $otherAdvisor = User::factory()->asesor()->create();

    $ownProperty = Property::factory()->create(['assigned_user_id' => $advisor->id]);
    $otherProperty = Property::factory()->create(['assigned_user_id' => $otherAdvisor->id]);
    $ownFollowUp = FollowUp::factory()->create(['assigned_user_id' => $advisor->id]);
    $otherFollowUp = FollowUp::factory()->create(['assigned_user_id' => $otherAdvisor->id]);
    $ownSale = Sale::factory()->create(['advisor_user_id' => $advisor->id]);
    $otherSale = Sale::factory()->create(['advisor_user_id' => $otherAdvisor->id]);
    $ownReservation = Reservation::factory()->create(['user_id' => $advisor->id]);
    $otherReservation = Reservation::factory()->create(['user_id' => $otherAdvisor->id]);
    $ownCommission = Commission::factory()->create(['advisor_user_id' => $advisor->id]);
    $otherCommission = Commission::factory()->create(['advisor_user_id' => $otherAdvisor->id]);

    expect(Property::query()->visibleFor($advisor)->pluck('id')->all())->toEqualCanonicalizing([$ownProperty->id]);
    expect(FollowUp::query()->visibleFor($advisor)->pluck('id')->all())->toEqualCanonicalizing([$ownFollowUp->id]);
    expect(Sale::query()->visibleFor($advisor)->pluck('id')->all())->toEqualCanonicalizing([$ownSale->id]);
    expect(Reservation::query()->visibleFor($advisor)->pluck('id')->all())->toEqualCanonicalizing([$ownReservation->id]);
    expect(Commission::query()->visibleFor($advisor)->pluck('id')->all())->toEqualCanonicalizing([$ownCommission->id]);

    expect(Gate::forUser($advisor)->denies('view', $otherProperty))->toBeTrue();
    expect(Gate::forUser($advisor)->denies('view', $otherFollowUp))->toBeTrue();
    expect(Gate::forUser($advisor)->denies('view', $otherSale))->toBeTrue();
    expect(Gate::forUser($advisor)->denies('view', $otherReservation))->toBeTrue();
    expect(Gate::forUser($advisor)->denies('view', $otherCommission))->toBeTrue();
});
