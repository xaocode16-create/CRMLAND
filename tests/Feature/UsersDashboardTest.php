<?php

use Database\Seeders\CrmCoreSeeder;
use Livewire\Livewire;

test('a supervisor only sees users assigned to their team', function () {
    $this->seed(CrmCoreSeeder::class);

    $supervisor = User::factory()->supervisor()->create(['name' => 'Supervisora General']);
    $teamAdvisor = User::factory()->asesor()->create([
        'name' => 'Asesora del equipo',
        'supervisor_user_id' => $supervisor->id,
    ]);
    $externalAdvisor = User::factory()->asesor()->create(['name' => 'Asesor externo']);

    $component = Livewire::actingAs($supervisor)->test('pages::users.index');

    $component
        ->assertSee($supervisor->name)
        ->assertSee($teamAdvisor->name)
        ->assertDontSee($externalAdvisor->name);
});

test('an administrator can create a user from the floating form', function () {
    $this->seed(CrmCoreSeeder::class);

    $administrator = User::factory()->administrador()->create();
    $advisorRole = Role::query()->where('slug', 'asesor')->firstOrFail();

    Livewire::actingAs($administrator)
        ->test('pages::users.index')
        ->call('create')
        ->set('form', [
            'name' => 'Nuevo asesor',
            'username' => 'nuevo.asesor',
            'email' => 'nuevo.asesor@example.com',
            'phone' => '+51 999 888 777',
            'password' => 'password-123',
            'role_id' => $advisorRole->id,
            'supervisor_user_id' => null,
            'status' => 'active',
        ])
        ->call('save')
        ->assertSet('showForm', false);

    $this->assertDatabaseHas('users', [
        'name' => 'Nuevo asesor',
        'email' => 'nuevo.asesor@example.com',
        'role_id' => $advisorRole->id,
    ]);
});
