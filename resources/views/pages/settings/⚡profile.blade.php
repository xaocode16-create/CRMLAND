<?php

use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Mi perfil')] class extends Component {
    use ProfileValidationRules;
    use WithFileUploads;

    public string $name = '';
    public ?string $username = null;
    public string $email = '';
    public ?string $phone = null;
    public mixed $avatar = null;
    public bool $isEditing = false;

    public function mount(): void
    {
        $this->fillProfileFields(Auth::user());
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => $this->nameRules(),
            'username' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique(User::class, 'username')->ignore($user->getKey()),
            ],
            'email' => $this->emailRules($user->getKey()),
            'phone' => ['nullable', 'string', 'max:30'],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048'],
        ]);

        $avatarFile = $validated['avatar'] ?? null;
        $oldAvatar = $user->avatar;
        $profile = Arr::only($validated, ['name', 'username', 'email', 'phone']);

        if ($avatarFile !== null) {
            $avatarPath = $avatarFile->store('avatars/'.$user->getKey(), 'public');

            if (! is_string($avatarPath)) {
                $this->addError('avatar', 'No se pudo guardar la imagen. Inténtalo de nuevo.');

                return;
            }

            $profile['avatar'] = $avatarPath;
        }

        $user->fill($profile);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($avatarFile !== null && filled($oldAvatar) && $oldAvatar !== $user->avatar) {
            Storage::disk('public')->delete($oldAvatar);
        }

        $this->fillProfileFields($user);
        $this->avatar = null;
        $this->isEditing = false;

        Flux::toast(variant: 'success', text: 'Perfil actualizado.');
    }

    public function cancelEditing(): void
    {
        $this->fillProfileFields(Auth::user());
        $this->avatar = null;
        $this->isEditing = false;
        $this->resetValidation();
    }

    public function resendVerificationNotification(): void
    {
        $user = Auth::user();

        if ($user->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('dashboard', absolute: false));

            return;
        }

        $user->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    #[Computed]
    public function hasUnverifiedEmail(): bool
    {
        $user = Auth::user();

        return $user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail();
    }

    #[Computed]
    public function showDeleteUser(): bool
    {
        $user = Auth::user();

        return ! $user instanceof MustVerifyEmail
            || ($user instanceof MustVerifyEmail && $user->hasVerifiedEmail());
    }

    private function fillProfileFields(User $user): void
    {
        $this->name = $user->name;
        $this->username = $user->username;
        $this->email = $user->email;
        $this->phone = $user->phone;
    }
}; ?>

<section class="crm-page crm-profile-page">
    <header class="crm-page-header crm-profile-heading">
        <div>
            <p class="crm-eyebrow">Mi cuenta</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Mi perfil</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Administra tu información personal y revisa el estado de tu cuenta.</p>
        </div>

        @if (! $isEditing)
            <flux:button variant="primary" icon="pencil-square" wire:click="$set('isEditing', true)">
                Editar perfil
            </flux:button>
        @endif
    </header>

    @php($user = auth()->user())

    <section class="crm-profile-hero">
        <div class="crm-profile-portrait">
            @if ($avatar)
                <img src="{{ $avatar->temporaryUrl() }}" alt="Vista previa de la foto de perfil">
            @elseif ($user->avatar)
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($user->avatar) }}" alt="Foto de perfil de {{ $user->name }}">
            @else
                <span>{{ $user->initials() }}</span>
            @endif
        </div>
        <div class="crm-profile-identity">
            <p class="crm-profile-kicker">Perfil de usuario</p>
            <h2>{{ $user->name }}</h2>
            <p>{{ $user->role?->name ?? 'Asesor' }} <span aria-hidden="true">·</span> {{ $user->email }}</p>
        </div>
        <span class="crm-profile-status {{ $user->status === 'active' ? 'is-active' : 'is-inactive' }}">
            <span aria-hidden="true"></span>
            {{ $user->status === 'active' ? 'Cuenta activa' : 'Cuenta inactiva' }}
        </span>
    </section>

    <div class="crm-profile-grid">
        <section class="crm-card crm-profile-card">
            <div class="crm-profile-card-heading">
                <div>
                    <p class="crm-eyebrow">Datos de cuenta</p>
                    <h2>Información personal</h2>
                </div>
                @if ($isEditing)
                    <span class="crm-profile-editing">Modo edición</span>
                @endif
            </div>

            @if ($isEditing)
                <form wire:submit="updateProfileInformation" class="crm-profile-form">
                    <div class="crm-profile-photo-field">
                        <label for="profile-avatar">Foto de perfil</label>
                        <div class="crm-profile-photo-control">
                            <div class="crm-profile-photo-preview">
                                @if ($avatar)
                                    <img src="{{ $avatar->temporaryUrl() }}" alt="Vista previa de la foto de perfil">
                                @elseif ($user->avatar)
                                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($user->avatar) }}" alt="Foto de perfil actual">
                                @else
                                    <span>{{ $user->initials() }}</span>
                                @endif
                            </div>
                            <div>
                                <label for="profile-avatar" class="crm-profile-upload-button">Cambiar foto</label>
                                <input id="profile-avatar" type="file" wire:model="avatar" accept="image/jpeg,image/png,image/webp" class="sr-only">
                                <p>JPG, PNG o WEBP. Máximo 2 MB.</p>
                                <div wire:loading wire:target="avatar" class="crm-profile-uploading">Cargando imagen…</div>
                                <flux:error name="avatar" />
                            </div>
                        </div>
                    </div>

                    <div class="crm-profile-fields">
                        <flux:input wire:model="name" label="Nombre completo" type="text" required autocomplete="name" />
                        <flux:input wire:model="username" label="Nombre de usuario" type="text" autocomplete="username" />
                        <flux:input wire:model="email" label="Correo electrónico" type="email" required autocomplete="email" />
                        <flux:input wire:model="phone" label="Teléfono" type="tel" autocomplete="tel" />
                    </div>

                    <div class="crm-profile-form-actions">
                        <flux:button type="button" variant="ghost" wire:click="cancelEditing">Cancelar</flux:button>
                        <flux:button variant="primary" type="submit" data-test="update-profile-button">
                            Guardar cambios
                        </flux:button>
                    </div>
                </form>
            @else
                <dl class="crm-profile-details">
                    <div>
                        <dt>Nombre completo</dt>
                        <dd>{{ $user->name }}</dd>
                    </div>
                    <div>
                        <dt>Nombre de usuario</dt>
                        <dd>{{ $user->username ?: 'Sin registrar' }}</dd>
                    </div>
                    <div>
                        <dt>Correo electrónico</dt>
                        <dd>{{ $user->email }}</dd>
                    </div>
                    <div>
                        <dt>Teléfono</dt>
                        <dd>{{ $user->phone ?: 'Sin registrar' }}</dd>
                    </div>
                </dl>

                @if ($this->hasUnverifiedEmail)
                    <div class="crm-profile-verification">
                        <span>Tu correo todavía no está verificado.</span>
                        <flux:link class="cursor-pointer" wire:click.prevent="resendVerificationNotification">
                            Reenviar correo de verificación
                        </flux:link>
                        @if (session('status') === 'verification-link-sent')
                            <span class="font-medium text-green-700">Se envió un nuevo enlace de verificación.</span>
                        @endif
                    </div>
                @endif
            @endif
        </section>

        <div class="crm-profile-side">
            <section class="crm-card crm-profile-card">
                <div class="crm-profile-card-heading">
                    <div>
                        <p class="crm-eyebrow">Protección</p>
                        <h2>Seguridad de la cuenta</h2>
                    </div>
                    <i class="bi bi-shield-lock" aria-hidden="true"></i>
                </div>
                <ul class="crm-profile-status-list">
                    <li>
                        <span>Correo electrónico</span>
                        <strong class="{{ $user->hasVerifiedEmail() ? 'is-positive' : 'is-pending' }}">
                            {{ $user->hasVerifiedEmail() ? 'Verificado' : 'Pendiente' }}
                        </strong>
                    </li>
                    <li>
                        <span>Autenticación de dos factores</span>
                        <strong class="{{ $user->hasEnabledTwoFactorAuthentication() ? 'is-positive' : 'is-pending' }}">
                            {{ $user->hasEnabledTwoFactorAuthentication() ? 'Activada' : 'No activada' }}
                        </strong>
                    </li>
                </ul>
                @if ($user->hasVerifiedEmail())
                    <flux:button :href="route('security.edit')" variant="ghost" icon="arrow-right" wire:navigate>
                        Administrar seguridad
                    </flux:button>
                @endif
            </section>

            <section class="crm-card crm-profile-card">
                <div class="crm-profile-card-heading">
                    <div>
                        <p class="crm-eyebrow">Información</p>
                        <h2>Actividad de la cuenta</h2>
                    </div>
                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                </div>
                <dl class="crm-profile-status-list">
                    <div>
                        <dt>Miembro desde</dt>
                        <dd>{{ $user->created_at?->translatedFormat('d M Y') ?? 'Sin registro' }}</dd>
                    </div>
                    <div>
                        <dt>Último acceso</dt>
                        <dd>{{ $user->last_login_at?->diffForHumans() ?? 'Sin actividad registrada' }}</dd>
                    </div>
                    <div>
                        <dt>Tipo de cuenta</dt>
                        <dd>{{ $user->role?->name ?? 'Asesor' }}</dd>
                    </div>
                </dl>
            </section>

            <section class="crm-card crm-profile-card crm-profile-preferences">
                <div>
                    <p class="crm-eyebrow">Personalización</p>
                    <h2>Preferencias</h2>
                    <p>Configura cómo se ve tu espacio de trabajo.</p>
                </div>
                <flux:button :href="route('appearance.edit')" variant="ghost" icon="arrow-right" wire:navigate>
                    Ajustar apariencia
                </flux:button>
            </section>
        </div>
    </div>

    @if ($this->showDeleteUser)
        <section class="crm-card crm-profile-delete">
            <livewire:pages::settings.delete-user-form />
        </section>
    @endif
</section>
