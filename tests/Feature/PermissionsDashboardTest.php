<?php

use App\Models\Permission;
use App\Models\User;
use Database\Seeders\CrmCoreSeeder;
use Livewire\Livewire;

test('an administrator can create a permission from the permissions dashboard', function () {
    $this->seed(CrmCoreSeeder::class);

    $administrator = User::factory()->administrador()->create();

    Livewire::actingAs($administrator)
        ->test('pages::permissions.index')
        ->call('createPermission')
        ->set('form', [
            'name' => 'Exportar reportes',
            'slug' => 'reports.export',
            'description' => 'Permite exportar reportes del CRM.',
            'module' => 'Reportes',
        ])
        ->call('savePermission')
        ->assertSet('showForm', false);

    $this->assertDatabaseHas('permissions', [
        'name' => 'Exportar reportes',
        'slug' => 'reports.export',
        'module' => 'Reportes',
    ]);
});

test('a user with permission access only sees permissions linked to their role', function () {
    $this->seed(CrmCoreSeeder::class);

    $supervisor = User::factory()->supervisor()->create();
    $supervisorPermission = Permission::factory()->create([
        'name' => 'Consultar permisos',
        'slug' => 'permissions.view_custom',
        'module' => 'Permisos',
    ]);
    $administratorOnlyPermission = Permission::factory()->create([
        'name' => 'Configurar permisos',
        'slug' => 'permissions.manage_custom',
        'module' => 'Permisos',
    ]);

    $supervisor->role->permissions()->syncWithoutDetaching([
        Permission::query()->where('slug', 'permissions.view')->value('id'),
        $supervisorPermission->id,
    ]);
    User::factory()->administrador()->create()->role->permissions()->syncWithoutDetaching([$administratorOnlyPermission->id]);

    Livewire::actingAs($supervisor)
        ->test('pages::permissions.index')
        ->assertSee('Consultar permisos')
        ->assertDontSee('Configurar permisos');
});
