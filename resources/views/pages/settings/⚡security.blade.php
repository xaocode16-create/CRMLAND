<?php

use App\Concerns\PasswordValidationRules;
use App\Models\AuditLog;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Passkeys\Actions\DeletePasskey;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Configuración de seguridad')] class extends Component {
    use PasswordValidationRules;

    public string $current_password = '';

    public string $password = '';

    public string $password_confirmation = '';

    public bool $canManageTwoFactor = false;

    public bool $twoFactorEnabled = false;

    public bool $requiresConfirmation = false;

    #[Locked]
    public bool $canManagePasskeys = false;

    #[Locked]
    public bool $canManageSessions = false;

    #[Locked]
    public array $passkeys = [];

    public array $securityAlerts = [
        'email' => true,
        'push' => true,
        'location' => true,
    ];

    public bool $showDeleteModal = false;

    public bool $showSessionsModal = false;

    public string $sessionPassword = '';

    #[Locked]
    public ?int $deletingPasskeyId = null;

    #[Locked]
    public string $deletingPasskeyName = '';

    public function mount(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $user = Auth::user();
        $this->canManageTwoFactor = Features::canManageTwoFactorAuthentication();
        $this->canManageSessions = config('session.driver') === 'database';

        if ($this->canManageTwoFactor) {
            if (Fortify::confirmsTwoFactorAuthentication() && is_null($user->two_factor_confirmed_at)) {
                $disableTwoFactorAuthentication($user);
            }

            $this->twoFactorEnabled = $user->hasEnabledTwoFactorAuthentication();
            $this->requiresConfirmation = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        $this->canManagePasskeys = Features::canManagePasskeys();
        $this->securityAlerts = array_merge($this->securityAlerts, $user->preferences['security_alerts'] ?? []);

        if ($this->canManagePasskeys) {
            $this->loadPasskeys();
        }
    }

    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => $this->currentPasswordRules(),
                'password' => $this->passwordRules(),
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => $validated['password'],
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        Flux::toast(variant: 'success', text: 'Contrasena actualizada.');
    }

    public function toggleSecurityAlert(string $key): void
    {
        if (! array_key_exists($key, $this->securityAlerts)) {
            return;
        }

        $this->securityAlerts[$key] = ! $this->securityAlerts[$key];
        $this->persistSecurityAlerts();
        Flux::toast(variant: 'success', text: 'Preferencias de seguridad actualizadas.');
    }

    public function loadPasskeys(): void
    {
        $this->passkeys = Auth::user()->passkeys()
            ->select(['id', 'name', 'credential', 'created_at', 'last_used_at'])
            ->latest()
            ->get()
            ->map(fn ($passkey): array => [
                'id' => $passkey->id,
                'name' => $passkey->name,
                'authenticator' => $passkey->authenticator,
                'created_at_diff' => $passkey->created_at->diffForHumans(),
                'last_used_at_diff' => $passkey->last_used_at?->diffForHumans(),
            ])
            ->toArray();
    }

    public function confirmDelete(int $passkeyId): void
    {
        $passkey = Auth::user()->passkeys()->findOrFail($passkeyId);

        $this->deletingPasskeyId = $passkey->id;
        $this->deletingPasskeyName = $passkey->name;
        $this->showDeleteModal = true;
    }

    public function deletePasskey(DeletePasskey $deletePasskey): void
    {
        if (! $this->deletingPasskeyId) {
            return;
        }

        $passkey = Auth::user()->passkeys()->findOrFail($this->deletingPasskeyId);

        $deletePasskey(Auth::user(), $passkey);

        $this->closeDeleteModal();
        $this->loadPasskeys();
        Flux::toast(variant: 'success', text: 'Llave de acceso eliminada.');
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->deletingPasskeyId = null;
        $this->deletingPasskeyName = '';
    }

    public function closeOtherSessions(): void
    {
        abort_unless($this->canManageSessions, 403);

        $this->validate([
            'sessionPassword' => ['required', 'string', 'current_password'],
        ]);

        Auth::logoutOtherDevices($this->sessionPassword);

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', Auth::id())
            ->where('id', '!=', Session::getId())
            ->delete();

        $this->reset('sessionPassword', 'showSessionsModal');
        unset($this->connectedSessions);

        Flux::toast(variant: 'success', text: 'Se cerraron las otras sesiones.');
    }

    #[On('two-factor-enabled')]
    public function onTwoFactorEnabled(): void
    {
        $this->twoFactorEnabled = true;
    }

    public function disable(DisableTwoFactorAuthentication $disableTwoFactorAuthentication): void
    {
        $disableTwoFactorAuthentication(Auth::user());
        $this->twoFactorEnabled = false;
        Flux::toast(variant: 'success', text: 'Autenticacion de dos factores desactivada.');
    }

    #[Computed]
    public function roleInfo(): array
    {
        $user = Auth::user();

        return match (true) {
            $user->isAdministrador() => ['name' => 'Administrador', 'icon' => 'bi-shield-check', 'tone' => 'blue'],
            $user->isSupervisor() => ['name' => 'Supervisor', 'icon' => 'bi-people', 'tone' => 'purple'],
            default => ['name' => 'Asesor', 'icon' => 'bi-person', 'tone' => 'green'],
        };
    }

    #[Computed]
    public function securityStatus(): array
    {
        $user = Auth::user();
        $checks = collect([
            $user->hasVerifiedEmail(),
            $this->twoFactorEnabled,
            $this->canManagePasskeys && count($this->passkeys) > 0,
        ])->filter()->count();

        return match ($checks) {
            3 => ['label' => 'Protegida', 'description' => 'Tus métodos principales de acceso están protegidos.', 'tone' => 'green', 'icon' => 'bi-shield-check'],
            2 => ['label' => 'Buena', 'description' => 'Puedes mejorar la protección activando otra medida.', 'tone' => 'blue', 'icon' => 'bi-shield'],
            default => ['label' => 'Reforzar', 'description' => 'Activa 2FA o una llave de acceso para mejorarla.', 'tone' => 'amber', 'icon' => 'bi-shield-exclamation'],
        };
    }

    #[Computed]
    public function connectedSessions(): Collection
    {
        $sessions = $this->canManageSessions
            ? DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->where('user_id', Auth::id())
                ->where('last_activity', '>=', now()->subMinutes(config('session.lifetime'))->timestamp)
                ->orderByDesc('last_activity')
                ->get(['id', 'user_agent', 'ip_address', 'last_activity'])
            : collect();

        if (! $sessions->contains('id', Session::getId())) {
            $sessions->prepend((object) [
                'id' => Session::getId(),
                'user_agent' => request()->userAgent() ?? '',
                'ip_address' => request()->ip(),
                'last_activity' => now()->timestamp,
            ]);
        }

        return $sessions->map(function (object $session): array {
            $agent = $session->user_agent ?? '';
            $browser = match (true) {
                Str::contains($agent, ['Edg/', 'EdgiOS/', 'EdgA/']) => 'Edge',
                Str::contains($agent, ['Chrome/', 'CriOS/']) => 'Chrome',
                Str::contains($agent, ['Firefox/', 'FxiOS/']) => 'Firefox',
                Str::contains($agent, 'Safari/') => 'Safari',
                default => 'Navegador web',
            };

            return [
                'id' => $session->id,
                'browser' => $browser,
                'platform' => match (true) {
                    Str::contains($agent, 'Android') => 'Android',
                    Str::contains($agent, ['iPhone', 'iPad']) => 'iOS',
                    Str::contains($agent, 'Windows') => 'Windows',
                    Str::contains($agent, 'Macintosh') => 'macOS',
                    Str::contains($agent, 'Linux') => 'Linux',
                    default => 'Dispositivo',
                },
                'icon' => match ($browser) {
                    'Chrome' => 'bi-browser-chrome',
                    'Edge' => 'bi-browser-edge',
                    'Firefox' => 'bi-browser-firefox',
                    'Safari' => 'bi-browser-safari',
                    default => 'bi-display',
                },
                'ip' => $session->ip_address ?? 'Sin registro',
                'date' => Carbon::createFromTimestamp($session->last_activity, 'America/Lima'),
                'current' => $session->id === Session::getId(),
            ];
        })->sortByDesc('current')->values();
    }

    #[Computed]
    public function securityActivity(): Collection
    {
        $activity = AuditLog::query()
            ->where('actor_user_id', Auth::id())
            ->where(function ($query): void {
                $query
                    ->whereIn('module', ['Seguridad', 'Security', 'Sistema'])
                    ->orWhereIn('action', ['password.updated', 'two_factor.enabled', 'two_factor.disabled', 'passkey.created', 'passkey.deleted', 'security.updated']);
            })
            ->latest('created_at')
            ->limit(6)
            ->get(['action', 'module', 'description', 'created_at'])
            ->map(fn (AuditLog $log): array => [
                'title' => $log->description ?: Str::headline($log->action),
                'description' => $log->module ?: 'Seguridad',
                'date' => $log->created_at,
                'icon' => $this->activityIcon($log->action),
            ]);

        if ($activity->isNotEmpty()) {
            return $activity;
        }

        return collect([
            [
                'title' => 'Estado de seguridad revisado',
                'description' => $this->securityStatus['label'].' · cuenta protegida',
                'date' => Auth::user()->updated_at,
                'icon' => 'bi-shield-check',
            ],
            [
                'title' => 'Cuenta creada',
                'description' => 'Registro inicial de acceso',
                'date' => Auth::user()->created_at,
                'icon' => 'bi-person-check',
            ],
        ]);
    }

    #[Computed]
    public function securityRecommendations(): array
    {
        return [
            [
                'label' => '2FA '.($this->twoFactorEnabled ? 'activada' : 'pendiente'),
                'description' => $this->twoFactorEnabled ? 'Tu cuenta usa autenticacion en dos pasos.' : 'Protege el acceso con un codigo de tu autenticador.',
                'icon' => $this->twoFactorEnabled ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill',
                'tone' => $this->twoFactorEnabled ? 'green' : 'amber',
            ],
            [
                'label' => 'Llaves de acceso '.(count($this->passkeys) > 0 ? 'configuradas' : 'pendientes'),
                'description' => count($this->passkeys) > 0 ? 'Tienes '.count($this->passkeys).' llave(s) registrada(s).' : 'Puedes iniciar sesion sin depender de una contrasena.',
                'icon' => count($this->passkeys) > 0 ? 'bi-check-circle-fill' : 'bi-info-circle-fill',
                'tone' => count($this->passkeys) > 0 ? 'green' : 'blue',
            ],
            [
                'label' => Auth::user()->hasVerifiedEmail() ? 'Correo verificado' : 'Correo pendiente de verificación',
                'description' => Auth::user()->hasVerifiedEmail() ? 'El correo de tu cuenta está confirmado.' : 'Verifica tu correo para reforzar la recuperación.',
                'icon' => Auth::user()->hasVerifiedEmail() ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill',
                'tone' => Auth::user()->hasVerifiedEmail() ? 'green' : 'amber',
            ],
        ];
    }

    #[Computed]
    public function securityAlertOptions(): array
    {
        return [
            ['key' => 'email', 'label' => 'Correo electrónico', 'description' => 'Recibe alertas de nuevos inicios de sesión.', 'icon' => 'bi-envelope'],
            ['key' => 'push', 'label' => 'Notificaciones en la aplicación', 'description' => 'Recibe avisos en tiempo real en tu navegador.', 'icon' => 'bi-phone'],
            ['key' => 'location', 'label' => 'Alertar por nueva ubicación', 'description' => 'Te avisaremos cuando se detecte una ubicación nueva.', 'icon' => 'bi-geo-alt'],
        ];
    }

    public function activityIcon(string $action): string
    {
        return match (true) {
            Str::contains($action, 'password') => 'bi-key',
            Str::contains($action, 'two_factor') => 'bi-shield-check',
            Str::contains($action, 'passkey') => 'bi-fingerprint',
            default => 'bi-shield-lock',
        };
    }

    private function persistSecurityAlerts(): void
    {
        $preferences = Auth::user()->preferences ?? [];
        $preferences['security_alerts'] = $this->securityAlerts;

        Auth::user()->forceFill(['preferences' => $preferences])->save();
    }
}; ?>

<section class="crm-security-page">
    <header class="crm-security-header">
        <div>
            <p class="crm-eyebrow">Mi cuenta</p>
            <h1>Seguridad</h1>
            <p>Protege tu cuenta, dispositivos y métodos de acceso en CRMLAND.</p>
        </div>
        <span class="crm-security-role-badge is-{{ $this->roleInfo['tone'] }}"><i class="bi {{ $this->roleInfo['icon'] }}"></i>{{ $this->roleInfo['name'] }}</span>
    </header>

    <nav class="crm-security-tabs" aria-label="Configuración de cuenta">
        <a href="{{ route('profile.edit') }}" wire:navigate><i class="bi bi-person"></i><span><strong>Perfil</strong><small>Información personal</small></span></a>
        <a href="{{ route('appearance.edit') }}" wire:navigate><i class="bi bi-sliders"></i><span><strong>Preferencias</strong><small>Apariencia y sistema</small></span></a>
        <a href="{{ route('security.edit') }}" class="is-active" wire:navigate><i class="bi bi-shield-check"></i><span><strong>Seguridad</strong><small>Contraseña y acceso</small></span></a>
    </nav>

    <section class="crm-security-metrics">
        <article class="crm-security-metric is-{{ $this->securityStatus['tone'] }}"><span><i class="bi {{ $this->securityStatus['icon'] }}"></i></span><div><small>Estado de seguridad</small><strong>{{ $this->securityStatus['label'] }}</strong></div><i class="bi bi-chevron-right"></i></article>
        <article class="crm-security-metric is-green"><span><i class="bi bi-lock"></i></span><div><small>2FA</small><strong>{{ $canManageTwoFactor && $twoFactorEnabled ? 'Activa' : 'Pendiente' }}</strong></div><i class="bi bi-chevron-right"></i></article>
        <article class="crm-security-metric is-blue"><span><i class="bi bi-display"></i></span><div><small>Sesiones activas</small><strong>{{ $this->connectedSessions->count() }}</strong></div><i class="bi bi-chevron-right"></i></article>
        <article class="crm-security-metric is-blue"><span><i class="bi bi-fingerprint"></i></span><div><small>Llaves de acceso</small><strong>{{ count($passkeys) }}</strong></div><i class="bi bi-chevron-right"></i></article>
    </section>

    <div class="crm-security-content">
        <main class="crm-security-main">
            <section class="crm-security-card">
                <div class="crm-security-section-heading"><span class="is-blue"><i class="bi bi-lock"></i></span><div><h2>Actualizar contrasena</h2><p>Usa una contrasena larga y segura para proteger tu cuenta.</p></div></div>
                <form method="POST" wire:submit="updatePassword" class="crm-security-password-form">
                    <flux:input wire:model="current_password" label="Contrasena actual" type="password" required autocomplete="current-password" viewable />
                    <flux:input wire:model="password" label="Nueva contrasena" type="password" required autocomplete="new-password" passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}" viewable />
                    <div class="crm-security-password-meter"><span></span><span></span><span></span><span></span><span></span><strong>Seguridad de la contrasena: Alta</strong></div>
                    <small class="crm-security-help">Mínimo 12 caracteres, mayúsculas, minúsculas, número y símbolo.</small>
                    <flux:input wire:model="password_confirmation" label="Confirmar contrasena" type="password" required autocomplete="new-password" passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}" viewable />
                    <div class="crm-security-form-actions"><flux:button variant="primary" type="submit" icon="lock-closed" data-test="update-password-button">Guardar cambios</flux:button></div>
                </form>
            </section>

            @if ($canManageTwoFactor)
                <section class="crm-security-card" wire:cloak>
                    <div class="crm-security-section-heading"><span class="is-green"><i class="bi bi-shield-lock"></i></span><div><h2>Autenticacion de dos factores</h2><p>Agrega una capa extra de seguridad al iniciar sesión.</p></div><span class="crm-security-inline-status {{ $twoFactorEnabled ? 'is-green' : 'is-amber' }}"><i class="bi bi-circle-fill"></i>{{ $twoFactorEnabled ? 'Activa' : 'Pendiente' }}</span></div>
                    @if ($twoFactorEnabled)
                        <div class="crm-security-method-card"><span><i class="bi bi-phone"></i></span><div><strong>Método actual: Aplicación autenticadora</strong><small>Recibe códigos de verificación desde tu app de autenticación.</small></div></div>
                        <div class="crm-security-button-row"><livewire:pages::settings.two-factor.recovery-codes :$requiresConfirmation /><flux:button variant="danger" wire:click="disable">Desactivar 2FA</flux:button></div>
                    @else
                        <div class="crm-security-info-box"><i class="bi bi-info-circle"></i><p>Al activar 2FA se solicitará un código seguro durante el inicio de sesión.</p></div>
                        <flux:modal.trigger name="two-factor-setup-modal"><flux:button variant="primary" wire:click="$dispatch('start-two-factor-setup')" icon="shield-check">Activar autenticacion de dos factores</flux:button></flux:modal.trigger>
                        <livewire:pages::settings.two-factor-setup-modal :requires-confirmation="$requiresConfirmation" />
                    @endif
                </section>
            @endif

            @if ($canManagePasskeys)
                <section class="crm-security-card" wire:cloak>
                    <div class="crm-security-section-heading"><span class="is-blue"><i class="bi bi-fingerprint"></i></span><div><h2>Llaves de acceso</h2><p>Inicia sesión sin contraseña usando dispositivos compatibles.</p></div><span class="crm-security-count-badge">{{ count($passkeys) }}</span></div>
                    <div class="crm-security-passkeys">
                        @forelse ($passkeys as $passkey)
                            <div class="crm-security-passkey" wire:key="security-passkey-{{ $passkey['id'] }}"><span class="crm-security-device-icon"><i class="bi bi-fingerprint"></i></span><span><strong>{{ $passkey['name'] }}</strong><small>{{ $passkey['authenticator'] ?: 'Dispositivo compatible' }} · Agregada {{ $passkey['created_at_diff'] }} @if ($passkey['last_used_at_diff']) · Último uso {{ $passkey['last_used_at_diff'] }}@endif</small></span><button type="button" wire:click="confirmDelete({{ $passkey['id'] }})" title="Eliminar llave"><i class="bi bi-trash3"></i></button></div>
                        @empty
                            <div class="crm-security-empty-passkeys"><span><i class="bi bi-fingerprint"></i></span><strong>Aun no tienes llaves de acceso</strong><small>Agrega una llave para iniciar sesión sin contraseña.</small></div>
                        @endforelse
                    </div>
                    <x-passkey-registration />
                </section>
            @endif
        </main>

        <aside class="crm-security-sidebar">
            <section class="crm-security-card">
                <div class="crm-security-side-heading"><span class="is-blue"><i class="bi bi-display"></i></span><h2>Sesiones activas</h2>@if ($this->connectedSessions->count() > 1)<button type="button" wire:click="$set('showSessionsModal', true)">Ver todas</button>@endif</div>
                <div class="crm-security-sessions">@foreach ($this->connectedSessions->take(4) as $session)<div class="crm-security-session" wire:key="security-session-{{ hash('sha256', $session['id']) }}"><span><i class="bi {{ $session['icon'] }}"></i></span><div><strong>{{ $session['platform'] }} · {{ $session['browser'] }}</strong><small><i class="bi bi-geo-alt"></i>{{ $session['ip'] }}</small></div><em class="{{ $session['current'] ? 'is-current' : '' }}">{{ $session['current'] ? 'Activa ahora' : $session['date']->locale('es')->isoFormat('DD MMM, HH:mm') }}</em><i class="bi bi-three-dots-vertical"></i></div>@endforeach</div>
                @if ($this->connectedSessions->count() > 1 && $canManageSessions)<button type="button" class="crm-security-danger-link" wire:click="$set('showSessionsModal', true)"><i class="bi bi-box-arrow-right"></i> Cerrar otras sesiones</button>@endif
            </section>

            <section class="crm-security-card">
                <div class="crm-security-side-heading"><span class="is-blue"><i class="bi bi-bell"></i></span><h2>Alertas de inicio de sesión</h2></div>
                <div class="crm-security-alert-list">@foreach ($this->securityAlertOptions as $option)<div><i class="bi {{ $option['icon'] }}"></i><span><strong>{{ $option['label'] }}</strong><small>{{ $option['description'] }}</small></span><button type="button" class="crm-security-switch {{ $securityAlerts[$option['key']] ? 'is-on' : '' }}" wire:click="toggleSecurityAlert('{{ $option['key'] }}')" aria-label="Cambiar {{ $option['label'] }}"><span></span></button></div>@endforeach</div>
            </section>

            <section class="crm-security-card">
                <div class="crm-security-side-heading"><span class="is-purple"><i class="bi bi-clock-history"></i></span><h2>Actividad de seguridad</h2><a href="{{ route('profile.edit') }}" wire:navigate>Ver historial</a></div>
                <div class="crm-security-activity">@foreach ($this->securityActivity as $activity)<div><span><i class="bi {{ $activity['icon'] }}"></i></span><span><strong>{{ $activity['title'] }}</strong><small>{{ $activity['description'] }}</small></span><time>{{ $activity['date']?->locale('es')->diffForHumans() }}</time></div>@endforeach</div>
            </section>

            <section class="crm-security-card">
                <div class="crm-security-side-heading"><span class="is-amber"><i class="bi bi-lightbulb"></i></span><h2>Recomendaciones</h2></div>
                <div class="crm-security-recommendations">@foreach ($this->securityRecommendations as $recommendation)<div><i class="bi {{ $recommendation['icon'] }} is-{{ $recommendation['tone'] }}"></i><span><strong>{{ $recommendation['label'] }}</strong><small>{{ $recommendation['description'] }}</small></span></div>@endforeach</div>
            </section>
        </aside>
    </div>

    <footer class="crm-security-footer"><span><i class="bi bi-shield-check"></i> Las medidas de seguridad se aplican a tu cuenta {{ $this->roleInfo['name'] }}.</span><a href="{{ route('dashboard') }}" wire:navigate>Volver al panel</a></footer>

    <flux:modal name="delete-passkey-modal" class="md:min-w-md crm-security-modal" @close="closeDeleteModal" wire:model="showDeleteModal">
        <div class="crm-security-modal-content"><div><flux:heading size="lg">Eliminar llave de acceso</flux:heading><flux:text>¿Deseas eliminar la llave de acceso "{{ $deletingPasskeyName }}"? Ya no podrás usarla para iniciar sesión.</flux:text></div><div class="crm-security-modal-actions"><flux:button variant="outline" wire:click="closeDeleteModal">Cancelar</flux:button><flux:button variant="danger" wire:click="deletePasskey">Eliminar llave de acceso</flux:button></div></div>
    </flux:modal>

    <flux:modal wire:model="showSessionsModal" class="crm-security-modal">
        <div class="crm-security-modal-content"><div><flux:heading size="lg">Cerrar otras sesiones</flux:heading><flux:text>Tu sesión actual se mantendrá abierta. Confirma tu contraseña para cerrar los demás dispositivos.</flux:text></div><form wire:submit="closeOtherSessions" class="crm-security-modal-form"><flux:input type="password" wire:model="sessionPassword" label="Contraseña actual" autocomplete="current-password" required />@error('sessionPassword')<flux:error name="sessionPassword" />@enderror<div class="crm-security-modal-actions"><flux:button type="button" variant="outline" wire:click="$set('showSessionsModal', false)">Cancelar</flux:button><flux:button type="submit" variant="primary" icon="arrow-right-start-on-rectangle">Cerrar otras sesiones</flux:button></div></form></div>
    </flux:modal>
</section>
