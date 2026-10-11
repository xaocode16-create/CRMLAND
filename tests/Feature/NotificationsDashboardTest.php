<?php

use App\Models\CrmNotification;
use App\Models\User;
use Database\Seeders\CrmCoreSeeder;
use Livewire\Livewire;

test('a supervisor sees notifications from their team but not from another team', function () {
    $this->seed(CrmCoreSeeder::class);

    $supervisor = User::factory()->supervisor()->create(['name' => 'Supervisora General']);
    $teamAdvisor = User::factory()->asesor()->create([
        'name' => 'Asesora del equipo',
        'supervisor_user_id' => $supervisor->id,
    ]);
    $externalAdvisor = User::factory()->asesor()->create(['name' => 'Asesor externo']);

    CrmNotification::query()->create([
        'user_id' => $teamAdvisor->id,
        'type' => 'LEAD_ASSIGNED',
        'title' => 'Lead del equipo',
        'body' => 'Notificación visible para la supervisora.',
        'status' => 'UNREAD',
        'created_at' => now(),
    ]);
    CrmNotification::query()->create([
        'user_id' => $externalAdvisor->id,
        'type' => 'LEAD_ASSIGNED',
        'title' => 'Lead externo',
        'body' => 'Notificación fuera del alcance.',
        'status' => 'UNREAD',
        'created_at' => now(),
    ]);

    Livewire::actingAs($supervisor)
        ->test('pages::notifications.index')
        ->assertSee('Lead del equipo')
        ->assertDontSee('Lead externo');
});

test('a user can mark their own notification as read', function () {
    $this->seed(CrmCoreSeeder::class);

    $advisor = User::factory()->asesor()->create();
    $notification = CrmNotification::query()->create([
        'user_id' => $advisor->id,
        'type' => 'FOLLOW_UP_DUE',
        'title' => 'Seguimiento pendiente',
        'body' => 'Revisa esta actividad.',
        'status' => 'UNREAD',
        'created_at' => now(),
    ]);

    Livewire::actingAs($advisor)
        ->test('pages::notifications.index')
        ->call('markRead', $notification->id);

    $this->assertDatabaseHas('notifications', [
        'id' => $notification->id,
        'status' => 'READ',
    ]);
});
