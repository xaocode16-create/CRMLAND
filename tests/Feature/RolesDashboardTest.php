<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\CrmCoreSeeder;
use Livewire\Livewire;

test('an administrator can create a custom role from the roles dashboard', function () {
    $this->seed(CrmCoreSeeder::class);

    $administrator = User::factory()->administrador()->create();

    Livewire::actingAs($administrator)
        ->test('pages::roles.index')
        ->call('createRole')
        ->set('form', [
            'name' => 'Marketing',
            'slug' => 'marketing',
            'description' => 'Acceso al equipo de marketing.',
            'status' => 'active',
        ])
        ->call('saveRole')
        ->assertSet('showForm', false);

    expect(Role::query()->where('slug', 'marketing')->value('name'))->toBe('Marketing');
});

test('a supervisor cannot access role administration without the role permission', function () {
    $this->seed(CrmCoreSeeder::class);
    $supervisor = User::factory()->supervisor()->create();

    $this->actingAs($supervisor)
        ->get(route('roles.index'))
        ->assertForbidden();
});
