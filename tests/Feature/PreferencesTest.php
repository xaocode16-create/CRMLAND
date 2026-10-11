<?php

use App\Models\User;
use Livewire\Livewire;

test('a user can save personal preferences', function () {
    $user = User::factory()->asesor()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.appearance')
        ->set('theme', 'dark')
        ->set('timezone', 'Europe/Madrid')
        ->set('notificationPreferences.email', false)
        ->set('homePage', 'leads')
        ->call('save');

    $preferences = $user->fresh()->preferences;

    expect($preferences)
        ->toMatchArray([
            'theme' => 'dark',
            'timezone' => 'Europe/Madrid',
            'home_page' => 'leads',
        ])
        ->and($preferences['notifications']['email'])->toBeFalse();
});
