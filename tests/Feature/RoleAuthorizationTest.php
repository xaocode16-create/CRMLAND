<?php

use App\Models\User;
use Database\Seeders\CrmCoreSeeder;

beforeEach(function () {
    $this->seed(CrmCoreSeeder::class);
});

test('asesores cannot access administration pages directly', function () {
    $this->actingAs(User::factory()->asesor()->create());

    $this->get(route('users.index'))->assertForbidden();
    $this->get(route('roles.index'))->assertForbidden();
    $this->get(route('permissions.index'))->assertForbidden();
    $this->get(route('settings.index'))->assertForbidden();
});

test('supervisors can access team users but not role administration', function () {
    $this->actingAs(User::factory()->supervisor()->create());

    $this->get(route('users.index'))->assertOk();
    $this->get(route('roles.index'))->assertForbidden();
});

test('administrators can access all administration pages', function () {
    $this->actingAs(User::factory()->administrador()->create());

    $this->get(route('users.index'))->assertOk();
    $this->get(route('roles.index'))->assertOk();
    $this->get(route('permissions.index'))->assertOk();
    $this->get(route('settings.index'))->assertRedirect(route('profile.edit'));
});

test('header quick action opens lead creation only for authorized users', function () {
    $this->actingAs(User::factory()->asesor()->create());

    $this->get(route('leads.index', ['showForm' => 1]))
        ->assertOk()
        ->assertSee('Nuevo lead');

    $this->actingAs(User::factory()->supervisor()->create());

    $this->get(route('leads.index', ['showForm' => 1]))
        ->assertForbidden();
});

test('global header keeps the same action slots across roles', function () {
    $roles = [
        User::factory()->administrador()->create(),
        User::factory()->supervisor()->create(),
        User::factory()->asesor()->create(),
    ];

    foreach ($roles as $user) {
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSee('crm-shell-header', false)
            ->assertSeeInOrder([
                'crm-shell-header',
                'crm-workspace',
                'crm-sidebar',
                'crm-workspace-content',
                'crm-shell-breadcrumb',
                'crm-main',
            ], false)
            ->assertSee('Actividad actual del equipo y oportunidades recientes.')
            ->assertSee('Asistente IA')
            ->assertDontSee('bi-plus-lg', false)
            ->assertSee('bi-bell', false)
            ->assertSee('global-user-menu-button', false);
    }
});
