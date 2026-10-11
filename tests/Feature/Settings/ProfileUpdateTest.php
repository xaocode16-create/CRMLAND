<?php

use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Models\AuditLog;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Property;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('Información personal')
        ->assertSee('Seguridad de la cuenta')
        ->assertSee('Objetivos y rendimiento')
        ->assertSee('Privilegios del asesor')
        ->assertSee('Actividad reciente')
        ->assertSee('Leads asignados')
        ->assertSee('Seguimientos activos')
        ->assertSee('Visitas agendadas')
        ->assertSee('Ventas del mes')
        ->assertSee('Cambiar foto de perfil')
        ->assertSee('crm-shell-header', false)
        ->assertSee('Buscar propiedades, leads, clientes...')
        ->assertSee('Mi perfil')
        ->assertSee('global-user-menu-button', false)
        ->assertSee('Cerrar sesión');
});

test('profile photo selector is available when editing', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::settings.profile')
        ->set('isEditing', true)
        ->assertSee('profile-avatar', false)
        ->assertSee('Cambiar foto');
});

test('advisor profile counts only assigned activity and current-month performance', function () {
    $advisor = User::factory()->asesor()->create();
    $otherAdvisor = User::factory()->asesor()->create();
    $lead = Lead::factory()->create([
        'assigned_user_id' => $advisor->id,
        'entered_at' => now(),
    ]);
    Lead::factory()->create(['assigned_user_id' => $otherAdvisor->id]);

    FollowUp::factory()->create([
        'lead_id' => $lead->id,
        'assigned_user_id' => $advisor->id,
        'type' => FollowUpType::Visit->value,
        'status' => FollowUpStatus::Pending->value,
        'scheduled_at' => now()->addDay(),
    ]);
    FollowUp::factory()->create([
        'lead_id' => $lead->id,
        'assigned_user_id' => $advisor->id,
        'type' => FollowUpType::Visit->value,
        'status' => FollowUpStatus::Completed->value,
        'completed_at' => now(),
    ]);
    FollowUp::factory()->create([
        'assigned_user_id' => $otherAdvisor->id,
        'status' => FollowUpStatus::Pending->value,
    ]);
    Sale::factory()->create([
        'lead_id' => $lead->id,
        'advisor_user_id' => $advisor->id,
        'sold_at' => now(),
    ]);
    Sale::factory()->create([
        'advisor_user_id' => $otherAdvisor->id,
        'sold_at' => now(),
    ]);

    $this->actingAs($advisor);

    $profileData = Livewire::test('pages::settings.profile')->instance()->profileData();

    expect(array_column($profileData['summary'], 'value'))->toBe([1, 1, 1, 1])
        ->and(array_column($profileData['performance'], 'value'))->toBe([1, 1, 1]);
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

test('advisor profile banner excludes other advisors records and administration modules', function () {
    $advisor = User::factory()->asesor()->create();
    $otherAdvisor = User::factory()->asesor()->create();
    Property::factory()->create(['assigned_user_id' => $advisor->id]);
    Property::factory()->count(2)->create(['assigned_user_id' => $otherAdvisor->id]);

    $this->actingAs($advisor);

    $profile = Livewire::test('pages::settings.profile');
    $metrics = $profile->instance()->bannerMetrics();
    $modules = array_column($profile->instance()->availableModules(), 'active');

    expect(array_column($metrics, 'value', 'label')['Propiedades asignadas'])->toBe(1)
        ->and(array_column($metrics, 'label'))->not->toContain('Usuarios registrados')
        ->and($modules)->not->toContain('users.index', 'roles.index', 'permissions.index', 'settings.index');

    $profile->assertDontSee('Acceso total al sistema');
});

test('administrator profile banner shows real totals and excludes inactive advisors and cancelled sales', function () {
    $admin = User::factory()->administrador()->create();
    $advisor = User::factory()->asesor()->create();
    User::factory()->asesor()->create(['status' => 'inactive']);
    $property = Property::factory()->create(['assigned_user_id' => $advisor->id, 'created_by' => $admin->id]);
    $lead = Lead::factory()->create(['assigned_user_id' => $advisor->id]);
    Lead::factory()->create(['assigned_user_id' => $advisor->id, 'status' => 'inactive']);
    foreach (['REGISTERED', 'CANCELLED'] as $status) {
        Sale::factory()->create([
            'lead_id' => $lead->id,
            'property_id' => $property->id,
            'advisor_user_id' => $advisor->id,
            'created_by' => $admin->id,
            'status' => $status,
        ]);
    }

    $this->actingAs($admin);

    $metrics = Livewire::test('pages::settings.profile')->instance()->bannerMetrics();

    expect(array_column($metrics, 'value'))->toBe([3, 1, 1, 1, 1]);
});

test('profile activity includes only actions performed by the signed in user', function () {
    $advisor = User::factory()->asesor()->create();
    $otherAdvisor = User::factory()->asesor()->create();
    LeadActivity::factory()->create(['user_id' => $advisor->id, 'title' => 'My lead note']);
    LeadActivity::factory()->create(['user_id' => $otherAdvisor->id, 'title' => 'Private lead note']);
    AuditLog::factory()->create([
        'actor_user_id' => $advisor->id,
        'action' => 'profile_updated',
        'entity_type' => User::class,
        'description' => 'My profile update',
        'created_at' => now(),
    ]);
    AuditLog::factory()->create([
        'actor_user_id' => $otherAdvisor->id,
        'action' => 'profile_updated',
        'entity_type' => User::class,
        'description' => 'Private profile update',
        'created_at' => now(),
    ]);

    $this->actingAs($advisor);

    Livewire::test('pages::settings.profile')
        ->assertSee('My lead note')
        ->assertSee('My profile update')
        ->assertDontSee('Private lead note')
        ->assertDontSee('Private profile update');
});

test('profile lists only unexpired sessions belonging to the current user', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $this->actingAs($user);
    config(['session.driver' => 'database', 'session.lifetime' => 120]);

    $currentSessionId = Session::getId();
    foreach ([
        [$currentSessionId, $user->id, now()->timestamp],
        ['my-other-session', $user->id, now()->subMinute()->timestamp],
        ['my-expired-session', $user->id, now()->subMinutes(121)->timestamp],
        ['another-users-session', $otherUser->id, now()->timestamp],
    ] as [$id, $userId, $lastActivity]) {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36',
            'payload' => 'private-session-payload',
            'last_activity' => $lastActivity,
        ]);
    }

    $profile = Livewire::test('pages::settings.profile');
    $devices = $profile->instance()->connectedSessions();

    expect($devices->pluck('id')->all())->toBe([$currentSessionId, 'my-other-session'])
        ->and($devices->first()['current'])->toBeTrue()
        ->and($devices->first()['browser'])->toBe('Chrome')
        ->and($devices->first()['platform'])->toBe('Windows');

    $profile->assertSee('Sesión actual')
        ->assertDontSee('private-session-payload')
        ->assertDontSee('my-other-session');
});

test('closing other sessions requires the correct password', function () {
    $user = User::factory()->create();
    $originalPasswordHash = $user->password;
    $this->actingAs($user);
    config(['session.driver' => 'database']);
    DB::table('sessions')->insert([
        'id' => 'my-other-session',
        'user_id' => $user->id,
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    Livewire::test('pages::settings.profile')
        ->set('sessionPassword', 'incorrect-password')
        ->call('closeOtherSessions')
        ->assertHasErrors(['sessionPassword']);

    $this->assertDatabaseHas('sessions', ['id' => 'my-other-session']);
    expect($user->refresh()->password)->toBe($originalPasswordHash);
});

test('closing other sessions preserves the current session and other users sessions', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $originalPasswordHash = $user->password;
    $this->actingAs($user);
    config(['session.driver' => 'database']);
    $currentSessionId = Session::getId();
    foreach ([[$currentSessionId, $user->id], ['my-other-session', $user->id], ['another-users-session', $otherUser->id]] as [$id, $userId]) {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);
    }

    Livewire::test('pages::settings.profile')
        ->set('showSessionsModal', true)
        ->set('sessionPassword', 'password')
        ->call('closeOtherSessions')
        ->assertHasNoErrors()
        ->assertSet('sessionPassword', '')
        ->assertSet('showSessionsModal', false);

    $this->assertDatabaseMissing('sessions', ['id' => 'my-other-session']);
    $this->assertDatabaseHas('sessions', ['id' => $currentSessionId]);
    $this->assertDatabaseHas('sessions', ['id' => 'another-users-session']);
    expect(auth()->id())->toBe($user->id)
        ->and($user->refresh()->password)->not->toBe($originalPasswordHash)
        ->and(Hash::check('password', $user->password))->toBeTrue();
});

test('closing sessions is unavailable for non database session stores', function () {
    $this->actingAs(User::factory()->create());
    config(['session.driver' => 'array']);

    Livewire::test('pages::settings.profile')
        ->set('sessionPassword', 'password')
        ->call('closeOtherSessions')
        ->assertForbidden();
});

test('starting profile editing restores saved fields without changing the role', function () {
    $user = User::factory()->asesor()->create();
    $this->actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('name', 'Unsaved name')
        ->set('email', 'unsaved@example.com')
        ->call('startEditing')
        ->assertSet('isEditing', true)
        ->assertSet('name', $user->name)
        ->assertSet('email', $user->email);

    expect($user->refresh()->isAsesor())->toBeTrue();
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
