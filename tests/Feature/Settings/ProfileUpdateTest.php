<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('Información personal')
        ->assertSee('Seguridad de la cuenta')
        ->assertSee('crm-shell-header', false)
        ->assertSee('Buscar propiedades, leads, clientes...')
        ->assertSee('Mi perfil')
        ->assertSee('global-user-menu-button', false)
        ->assertSee('Cerrar sesión');
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('username', 'test_user')
        ->set('email', 'test@example.com')
        ->set('phone', '987654321')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->username)->toEqual('test_user');
    expect($user->email)->toEqual('test@example.com');
    expect($user->phone)->toEqual('987654321');
    expect($user->email_verified_at)->toBeNull();
});

test('profile photo can be uploaded', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $user->forceFill(['username' => 'existing.user'])->save();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('avatar', UploadedFile::fake()->createWithContent(
            'profile.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB6kAAAAASUVORK5CYII=', true),
        ))
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->avatar)->toStartWith('avatars/'.$user->getKey().'/');
    Storage::disk('public')->assertExists($user->avatar);
    expect($user->username)->toBe('existing.user');
});

test('username must be unique across user profiles', function () {
    $user = User::factory()->create();
    $anotherUser = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('username', $anotherUser->username)
        ->call('updateProfileInformation')
        ->assertHasErrors(['username']);

    expect($user->refresh()->username)->not->toBe($anotherUser->username);
});

test('invalid profile photo is rejected without changing the saved profile', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $originalName = $user->name;

    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Changed Name')
        ->set('avatar', UploadedFile::fake()->create('profile.svg', 10, 'image/svg+xml'))
        ->call('updateProfileInformation')
        ->assertHasErrors(['avatar']);

    expect($user->refresh()->name)->toBe($originalName);
    expect($user->avatar)->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    expect($user->fresh())->toBeNull();
    expect(auth()->check())->toBeFalse();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});
