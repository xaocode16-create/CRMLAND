<?php

use App\Models\Lead;
use App\Models\User;
use Database\Seeders\CrmCoreSeeder;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->seed(CrmCoreSeeder::class);
    $user = User::factory()->asesor()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('dashboard shows only records inside the current advisor scope', function () {
    $this->seed(CrmCoreSeeder::class);
    $advisor = User::factory()->asesor()->create();
    $otherAdvisor = User::factory()->asesor()->create();
    $visibleLead = Lead::factory()->create([
        'assigned_user_id' => $advisor->id,
        'full_name' => 'Lead visible del asesor',
    ]);
    $hiddenLead = Lead::factory()->create([
        'assigned_user_id' => $otherAdvisor->id,
        'full_name' => 'Lead privado de otro asesor',
    ]);

    Livewire::actingAs($advisor)
        ->test('pages::dashboard')
        ->assertSee($visibleLead->full_name)
        ->assertDontSee($hiddenLead->full_name);
});

test('dashboard exposes role permissions according to the current role', function () {
    $this->seed(CrmCoreSeeder::class);

    Livewire::actingAs(User::factory()->administrador()->create())
        ->test('pages::dashboard')
        ->assertSee('Administrador')
        ->assertSee('Supervisor')
        ->assertSee('Asesor');

    Livewire::actingAs(User::factory()->supervisor()->create())
        ->test('pages::dashboard')
        ->assertSee('Supervisor')
        ->assertSee('Asesor')
        ->assertDontSee('Administrador');

    Livewire::actingAs(User::factory()->asesor()->create())
        ->test('pages::dashboard')
        ->assertSee('Asesor')
        ->assertDontSee('Administrador')
        ->assertDontSee('Supervisor');
});

test('administrator sees the administration role context in the navigation', function () {
    $this->seed(CrmCoreSeeder::class);
    $administrator = User::factory()->administrador()->create();

    $this->actingAs($administrator)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Administración')
        ->assertSee('Rol activo')
        ->assertSee('Administrador');
});
