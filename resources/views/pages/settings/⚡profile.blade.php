<?php

use App\Concerns\ProfileValidationRules;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Property;
use App\Models\Sale;
use App\Models\User;
use App\Support\Navigation\CrmNavigation;
use Flux\Flux;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Mi perfil')] class extends Component
{
    use ProfileValidationRules;
    use WithFileUploads;

    public string $name = '';

    public ?string $username = null;

    public string $email = '';

    public ?string $phone = null;

    public mixed $avatar = null;

    public bool $isEditing = false;

    public bool $showSessionsModal = false;

    public string $sessionPassword = '';

    public function mount(): void
    {
        $this->fillProfileFields(Auth::user());
    }

    /**
     * @return array{
     *     summary: list<array{label: string, value: int, icon: string}>,
     *     performance: list<array{label: string, value: int, detail: string, icon: string, color: string, ratio: int}>,
     *     activities: \Illuminate\Database\Eloquent\Collection<int, LeadActivity>,
     *     privileges: list<array{label: string, description: string, icon: string, route: string, allowed: bool}>
     * }
     */
    #[Computed]
    public function profileData(): array
    {
        $user = Auth::user()->loadMissing('role.permissions');
        $monthStart = now()->startOfMonth();
        $monthEnd = now();

        $monthlySales = Sale::query()
            ->visibleFor($user)
            ->whereNot('status', 'CANCELLED')
            ->whereBetween('sold_at', [$monthStart, $monthEnd])
            ->count();
        $monthlyVisits = FollowUp::query()
            ->visibleFor($user)
            ->where('type', FollowUpType::Visit->value)
            ->where('status', FollowUpStatus::Completed->value)
            ->whereBetween('completed_at', [$monthStart, $monthEnd])
            ->count();
        $monthlyLeads = Lead::query()
            ->visibleFor($user)
            ->whereBetween('entered_at', [$monthStart, $monthEnd])
            ->count();
        $monthlyMaximum = max(1, $monthlySales, $monthlyVisits, $monthlyLeads);

        return [
            'summary' => [
                [
                    'label' => 'Leads asignados',
                    'value' => Lead::query()->visibleFor($user)->count(),
                    'icon' => 'bi-people',
                ],
                [
                    'label' => 'Seguimientos activos',
                    'value' => FollowUp::query()
                        ->visibleFor($user)
                        ->where('status', FollowUpStatus::Pending->value)
                        ->count(),
                    'icon' => 'bi-check2-circle',
                ],
                [
                    'label' => 'Visitas agendadas',
                    'value' => FollowUp::query()
                        ->visibleFor($user)
                        ->where('type', FollowUpType::Visit->value)
                        ->where('status', FollowUpStatus::Pending->value)
                        ->where('scheduled_at', '>=', now())
                        ->count(),
                    'icon' => 'bi-calendar-event',
                ],
                [
                    'label' => 'Ventas del mes',
                    'value' => $monthlySales,
                    'icon' => 'bi-bar-chart-line',
                ],
            ],
            'performance' => [
                [
                    'label' => 'Ventas registradas',
                    'value' => $monthlySales,
                    'detail' => 'Este mes',
                    'icon' => 'bi-bullseye',
                    'color' => 'blue',
                    'ratio' => (int) round($monthlySales / $monthlyMaximum * 100),
                ],
                [
                    'label' => 'Visitas realizadas',
                    'value' => $monthlyVisits,
                    'detail' => 'Este mes',
                    'icon' => 'bi-people',
                    'color' => 'green',
                    'ratio' => (int) round($monthlyVisits / $monthlyMaximum * 100),
                ],
                [
                    'label' => 'Leads captados',
                    'value' => $monthlyLeads,
                    'detail' => 'Este mes',
                    'icon' => 'bi-trophy',
                    'color' => 'purple',
                    'ratio' => (int) round($monthlyLeads / $monthlyMaximum * 100),
                ],
            ],
            'activities' => LeadActivity::query()
                ->where('user_id', $user->getKey())
                ->with('lead:id,full_name')
                ->latest()
                ->limit(4)
                ->get(),
            'privileges' => [
                [
                    'label' => 'Ver leads asignados',
                    'description' => 'Consulta los leads que tienes asignados.',
                    'icon' => 'bi-people',
                    'route' => 'leads.index',
                    'allowed' => $user->hasPermission('leads.view'),
                ],
                [
                    'label' => 'Gestionar seguimientos',
                    'description' => 'Crea y actualiza seguimientos de tus leads.',
                    'icon' => 'bi-file-earmark-text',
                    'route' => 'follow-ups.index',
                    'allowed' => $user->hasPermission('followups.view')
                        && $user->hasPermission('followups.create')
                        && $user->hasPermission('followups.edit'),
                ],
                [
                    'label' => 'Gestionar agenda y visitas',
                    'description' => 'Programa visitas para tus clientes.',
                    'icon' => 'bi-calendar-event',
                    'route' => 'reservations.index',
                    'allowed' => $user->hasPermission('appointments.view')
                        && $user->hasPermission('appointments.create'),
                ],
                [
                    'label' => 'Registrar reservas',
                    'description' => 'Registra reservas desde tus oportunidades.',
                    'icon' => 'bi-house',
                    'route' => 'reservations.index',
                    'allowed' => $user->hasPermission('appointments.view')
                        && $user->hasPermission('sales.create'),
                ],
                [
                    'label' => 'Consultar comisiones',
                    'description' => 'Revisa el estado de tus comisiones.',
                    'icon' => 'bi-bar-chart-line',
                    'route' => 'commissions.index',
                    'allowed' => $user->hasPermission('commissions.view'),
                ],
                [
                    'label' => 'Ver reportes personales',
                    'description' => 'Accede a tus reportes de rendimiento.',
                    'icon' => 'bi-grid',
                    'route' => 'reports.index',
                    'allowed' => $user->hasPermission('reports.view'),
                ],
            ],
        ];
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

    public function startEditing(): void
    {
        $this->fillProfileFields(Auth::user());
        $this->avatar = null;
        $this->resetValidation();
        $this->isEditing = true;
    }

    /** @return list<array{label: string, value: int, icon: string}> */
    #[Computed]
    public function bannerMetrics(): array
    {
        $user = Auth::user()->loadMissing('role');

        if (! $user->isAdministrador()) {
            return [
                ...$this->profileData['summary'],
                ['label' => 'Propiedades asignadas', 'value' => Property::query()->visibleFor($user)->count(), 'icon' => 'bi-buildings'],
            ];
        }

        return [
            ['label' => 'Usuarios registrados', 'value' => User::query()->count(), 'icon' => 'bi-buildings'],
            ['label' => 'Asesores activos', 'value' => User::query()->where('status', 'active')->whereHas('role', fn (Builder $query): Builder => $query->where('slug', UserRole::Asesor->value))->count(), 'icon' => 'bi-people'],
            ['label' => 'Propiedades registradas', 'value' => Property::query()->count(), 'icon' => 'bi-building'],
            ['label' => 'Leads en proceso', 'value' => Lead::query()->where('status', 'active')->count(), 'icon' => 'bi-graph-up-arrow'],
            ['label' => 'Ventas cerradas', 'value' => Sale::query()->whereNot('status', 'CANCELLED')->count(), 'icon' => 'bi-cash-coin'],
        ];
    }

    /** @return list<array{label: string, icon: string, href: string, active: string}> */
    #[Computed]
    public function availableModules(): array
    {
        return collect(app(CrmNavigation::class)->groupsFor(Auth::user()))
            ->except('Mi cuenta')
            ->flatten(1)
            ->values()
            ->all();
    }

    /** @return Collection<int, array{title: string, description: string, date: Carbon, icon: string, color: string}> */
    #[Computed]
    public function recentActivity(): Collection
    {
        $activities = $this->profileData['activities']->map(fn (LeadActivity $activity): array => [
            'title' => $activity->title,
            'description' => $activity->lead?->full_name ?? $activity->description ?? 'Actividad comercial',
            'date' => $activity->created_at,
            'icon' => 'bi-buildings',
            'color' => 'amber',
        ]);

        $audits = AuditLog::query()
            ->where('actor_user_id', Auth::id())
            ->latest()
            ->limit(8)
            ->get(['action', 'module', 'description', 'created_at'])
            ->map(fn (AuditLog $log): array => [
                'title' => Str::headline($log->action),
                'description' => $log->description ?? Str::headline($log->module),
                'date' => $log->created_at,
                'icon' => 'bi-check2-circle',
                'color' => 'blue',
            ]);

        return collect($activities->all())->concat($audits)->sortByDesc('date')->take(8)->values();
    }

    /** @return Collection<int, array{id: string, browser: string, platform: string, icon: string, ip: string, date: Carbon, current: bool}> */
    #[Computed]
    public function connectedSessions(): Collection
    {
        $sessions = config('session.driver') === 'database'
            ? DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
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
                    default => 'bi-display',
                },
                'ip' => $session->ip_address ?? 'Sin registro',
                'date' => Carbon::createFromTimestamp($session->last_activity, 'America/Lima'),
                'current' => $session->id === Session::getId(),
            ];
        })->sortByDesc('current')->values();
    }

    public function closeOtherSessions(): void
    {
        abort_unless(config('session.driver') === 'database', 403);

        $this->validate(['sessionPassword' => ['required', 'string', 'current_password']]);

        Auth::logoutOtherDevices($this->sessionPassword);

        DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
            ->where('user_id', Auth::id())
            ->where('id', '!=', Session::getId())
            ->delete();

        $this->reset('sessionPassword', 'showSessionsModal');
        unset($this->connectedSessions);

        Flux::toast(variant: 'success', text: 'Se cerraron las otras sesiones.');
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

<section class="crm-page crm-profile-page" x-data="{ tab: 'general' }">
    @php
        $user = auth()->user()->loadMissing('role.permissions', 'supervisor');
        $roleName = $user->role?->name ?? 'Asesor';
        $roleDescription = $user->isAsesor() ? 'Asesor comercial' : ($user->isAdministrador() ? 'Administrador del sistema' : $roleName);
        $joinedAt = $user->created_at?->timezone('America/Lima')->locale('es')->isoFormat('DD MMM YYYY') ?? 'Sin registro';
    @endphp

    <header class="crm-page-header crm-profile-heading">
        <div>
            <p class="crm-eyebrow">Mi cuenta</p>
            <h1 class="crm-module-title">Mi perfil</h1>
            <p class="crm-module-description">Administra tu información personal, revisa tus permisos y configura tu cuenta.</p>
        </div>
        <flux:button variant="primary" icon="pencil-square" wire:click="startEditing" class="crm-profile-edit-button">
            Editar perfil
        </flux:button>
    </header>

    <section class="crm-profile-hero" aria-label="Perfil de usuario">
        <div class="crm-profile-hero-identity">
            <div class="crm-profile-portrait">
                @if ($user->avatar)
                    <img src="{{ Storage::disk('public')->url($user->avatar) }}" alt="Foto de perfil de {{ $user->name }}">
                @else
                    <span class="crm-profile-initials">{{ $user->initials() }}</span>
                @endif
                <button type="button" class="crm-profile-avatar-edit" aria-label="Cambiar foto de perfil" title="Cambiar foto de perfil" wire:click="startEditing">
                    <i class="bi bi-camera" aria-hidden="true"></i>
                </button>
            </div>
            <div class="crm-profile-identity">
                <div class="crm-profile-identity-top">
                    <span class="crm-profile-overline">Perfil de usuario</span>
                    <span class="crm-profile-status {{ $user->status === 'active' ? 'is-active' : 'is-inactive' }}">
                        <span aria-hidden="true"></span>
                        {{ $user->status === 'active' ? 'Cuenta activa' : 'Cuenta inactiva' }}
                    </span>
                </div>
                <h2>{{ $user->name }}</h2>
                <p class="crm-profile-role">{{ $roleDescription }}</p>
                <div class="crm-profile-contact">
                    <a href="mailto:{{ $user->email }}"><i class="bi bi-envelope" aria-hidden="true"></i>{{ $user->email }}</a>
                    <span><i class="bi bi-telephone" aria-hidden="true"></i>{{ $user->phone ?: 'Sin teléfono registrado' }}</span>
                </div>
                <p class="crm-profile-joined">Miembro desde {{ $joinedAt }}</p>
            </div>
        </div>
        <div class="crm-profile-summary" aria-label="Resumen de actividad">
            @foreach ($this->bannerMetrics as $metric)
                <div wire:key="profile-summary-{{ $loop->index }}" class="crm-profile-summary-item">
                    <i class="bi {{ $metric['icon'] }}" aria-hidden="true"></i>
                    <strong>{{ $metric['value'] }}</strong>
                    <span>{{ $metric['label'] }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <nav class="crm-profile-tabs" aria-label="Secciones del perfil" role="tablist"
         @keydown.right.prevent="const tabs = Array.from($el.querySelectorAll('[role=tab]')); const next = tabs[(tabs.indexOf(document.activeElement) + 1) % tabs.length]; next.click(); next.focus()"
         @keydown.left.prevent="const tabs = Array.from($el.querySelectorAll('[role=tab]')); const next = tabs[(tabs.indexOf(document.activeElement) - 1 + tabs.length) % tabs.length]; next.click(); next.focus()">
        @foreach ([
            ['general', 'Información general', 'bi-person'],
            ['personal', 'Información personal', 'bi-person'],
            ['professional', 'Información profesional', 'bi-person-workspace'],
            ['permissions', 'Permisos y roles', 'bi-person-lock'],
            ['security', 'Seguridad', 'bi-shield-check'],
            ['activity', 'Actividad', 'bi-clock-history'],
            ['preferences', 'Preferencias', 'bi-sliders'],
        ] as [$tab, $label, $icon])
            <button type="button" wire:key="profile-tab-{{ $tab }}" role="tab" id="profile-tab-{{ $tab }}"
                    aria-controls="profile-content" :aria-selected="tab === '{{ $tab }}'" :tabindex="tab === '{{ $tab }}' ? 0 : -1"
                    :class="{ 'is-active': tab === '{{ $tab }}' }" @click="tab = '{{ $tab }}'">
                <i class="bi {{ $icon }}" aria-hidden="true"></i>{{ $label }}
            </button>
        @endforeach
    </nav>

    <div id="profile-content" class="crm-profile-grid" :class="{ 'is-focused': tab !== 'general' }" role="tabpanel" :aria-labelledby="'profile-tab-' + tab">
        <div class="crm-profile-main-column" x-show="['general', 'personal', 'permissions', 'activity'].includes(tab)">
            <x-profile-panel id="informacion-personal" title="Información personal" description="Tus datos de identificación y contacto." icon="bi-person"
                             x-show="tab === 'general' || tab === 'personal'">
                <x-slot:actions>
                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="startEditing" class="crm-profile-inline-edit">Editar</flux:button>
                </x-slot:actions>
                <dl class="crm-profile-details">
                    <div><dt>Nombre completo</dt><dd>{{ $user->name }}</dd></div>
                    <div><dt>Nombre de usuario</dt><dd>{{ $user->username ?: 'Sin registrar' }}</dd></div>
                    <div>
                        <dt>Correo electrónico</dt>
                        <dd class="crm-profile-email">{{ $user->email }}<span class="crm-profile-pill {{ $user->hasVerifiedEmail() ? 'is-success' : 'is-warning' }}">{{ $user->hasVerifiedEmail() ? 'Verificado' : 'Pendiente' }}</span></dd>
                    </div>
                    <div><dt>Teléfono</dt><dd>{{ $user->phone ?: 'Sin registrar' }}</dd></div>
                    <div><dt>Fecha de registro</dt><dd><i class="bi bi-calendar3" aria-hidden="true"></i>{{ $joinedAt }}</dd></div>
                    <div><dt>Estado de la cuenta</dt><dd class="{{ $user->status === 'active' ? 'is-positive' : 'is-pending' }}"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>{{ $user->status === 'active' ? 'Activa' : 'Inactiva' }}</dd></div>
                </dl>
                @if ($this->hasUnverifiedEmail)
                    <div class="crm-profile-verification">
                        <p>Tu correo todavía no está verificado.</p>
                        <flux:button size="sm" wire:click="resendVerificationNotification">Reenviar verificación</flux:button>
                        @if (session('status') === 'verification-link-sent')
                            <p>Se envió un nuevo enlace de verificación.</p>
                        @endif
                    </div>
                @endif
            </x-profile-panel>

            <x-profile-panel id="permisos-cuenta" title="Rol y privilegios" description="Módulos y permisos asignados a tu cuenta." icon="bi-shield-check"
                             x-show="tab === 'general' || tab === 'permissions'">
                <x-slot:actions><span class="crm-profile-role-badge">{{ $roleName }}</span></x-slot:actions>
                <div class="crm-profile-access-summary">
                    <i class="bi bi-person-check" aria-hidden="true"></i>
                    <div>
                        <strong>{{ $user->isAdministrador() ? 'Acceso total al sistema' : 'Acceso comercial asignado' }}</strong>
                        <p>{{ $user->isAdministrador() ? 'Como administrador tienes acceso completo a todos los módulos del CRM.' : 'Tus permisos corresponden a las funciones y los registros asignados a tu rol.' }}</p>
                    </div>
                </div>
                <div class="crm-profile-module-list">
                    @forelse ($this->availableModules as $module)
                        <a href="{{ $module['href'] }}" wire:navigate wire:key="profile-module-{{ $module['active'] }}"><i class="bi {{ $module['icon'] }}" aria-hidden="true"></i>{{ $module['label'] }}</a>
                    @empty
                        <p class="crm-profile-empty">No tienes módulos comerciales asignados.</p>
                    @endforelse
                </div>
                <div class="crm-profile-permission-details" x-show="tab === 'permissions'" x-cloak>
                    <h3>{{ $user->isAsesor() ? 'Privilegios del asesor' : 'Permisos de la cuenta' }}</h3>
                    @foreach ($this->profileData['privileges'] as $privilege)
                        <div wire:key="profile-permission-{{ $loop->index }}">
                            <i class="bi {{ $privilege['allowed'] ? 'bi-check-circle-fill is-positive' : 'bi-lock is-pending' }}" aria-hidden="true"></i>
                            <span><strong>{{ $privilege['label'] }}</strong><small>{{ $privilege['description'] }}</small></span>
                            <span>{{ $privilege['allowed'] ? 'Permitido' : 'No asignado' }}</span>
                        </div>
                    @endforeach
                </div>
            </x-profile-panel>

            <x-profile-panel id="actividad-cuenta" title="Actividad reciente" description="Últimas acciones realizadas en el sistema." icon="bi-clock"
                             x-show="tab === 'general' || tab === 'activity'">
                <x-slot:actions><button type="button" class="crm-profile-text-action" @click="tab = 'activity'"><i class="bi bi-chevron-right" aria-hidden="true"></i>Ver todos</button></x-slot:actions>
                <div class="crm-profile-activity-list">
                    @if ($user->last_login_at)
                        <div class="crm-profile-activity">
                            <span class="crm-profile-activity-icon is-green"><i class="bi bi-door-open" aria-hidden="true"></i></span>
                            <span><strong>Inicio de sesión</strong><small>Has iniciado sesión en el sistema.</small></span>
                            <time>{{ $user->last_login_at->timezone('America/Lima')->locale('es')->isoFormat('DD MMM, HH:mm') }}</time>
                        </div>
                    @endif
                    @forelse ($this->recentActivity as $activity)
                        <div class="crm-profile-activity" wire:key="profile-activity-{{ $loop->index }}" x-show="tab === 'activity' || {{ $loop->index }} < 4">
                            <span class="crm-profile-activity-icon is-{{ $activity['color'] }}"><i class="bi {{ $activity['icon'] }}" aria-hidden="true"></i></span>
                            <span><strong>{{ $activity['title'] }}</strong><small>{{ $activity['description'] }}</small></span>
                            <time>{{ $activity['date']->timezone('America/Lima')->locale('es')->isoFormat('DD MMM, HH:mm') }}</time>
                        </div>
                    @empty
                        <div class="crm-profile-activity">
                            <span class="crm-profile-activity-icon is-green"><i class="bi bi-door-open" aria-hidden="true"></i></span>
                            <span><strong>Sesión actual</strong><small>Tu cuenta está conectada al CRM.</small></span>
                            <span class="crm-profile-pill is-success">Activa</span>
                        </div>
                        <p class="crm-profile-empty">Todavía no hay otras acciones registradas.</p>
                    @endforelse
                </div>
            </x-profile-panel>

            <x-profile-panel title="Objetivos y rendimiento" description="Actividad comercial registrada este mes." icon="bi-bar-chart"
                             x-show="tab === 'activity'" x-cloak>
                <div class="crm-profile-performance-list">
                    @foreach ($this->profileData['performance'] as $metric)
                        <div wire:key="profile-performance-{{ $loop->index }}" class="crm-profile-performance-item">
                            <i class="bi {{ $metric['icon'] }}" aria-hidden="true"></i>
                            <div><strong>{{ $metric['label'] }}</strong><progress max="100" value="{{ $metric['ratio'] }}">{{ $metric['ratio'] }}%</progress></div>
                            <strong>{{ $metric['value'] }}</strong>
                        </div>
                    @endforeach
                </div>
            </x-profile-panel>
        </div>

        <div class="crm-profile-side" x-show="['general', 'professional', 'security', 'preferences'].includes(tab)">
            <x-profile-panel id="informacion-profesional" title="Información profesional" description="Tu rol y datos laborales en el CRM." icon="bi-briefcase"
                             x-show="tab === 'general' || tab === 'professional'">
                <x-slot:actions><flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="startEditing" title="Editar datos de contacto" class="crm-profile-inline-edit">Editar</flux:button></x-slot:actions>
                <dl class="crm-profile-details">
                    <div><dt>Cargo</dt><dd>{{ $roleName }}</dd></div>
                    <div><dt>Área / Departamento</dt><dd>{{ $user->isAdministrador() ? 'Administración' : 'Comercial' }}</dd></div>
                    <div><dt>Supervisor</dt><dd>{{ $user->supervisor?->name ?? 'Sin asignar' }}</dd></div>
                    <div><dt>Correo de contacto</dt><dd>{{ $user->email }}</dd></div>
                    <div class="crm-profile-bio"><dt>Descripción / Bio</dt><dd><i class="bi bi-file-earmark-text" aria-hidden="true"></i>{{ $user->role?->description ?: 'Sin descripción registrada.' }}</dd></div>
                </dl>
            </x-profile-panel>

            <x-profile-panel id="seguridad-cuenta" title="Seguridad de la cuenta" description="Gestiona la seguridad de tu cuenta." icon="bi-lock"
                             x-show="tab === 'general' || tab === 'security'">
                <div class="crm-profile-security-layout">
                    <dl class="crm-profile-security-list">
                        <div><dt>Correo electrónico</dt><dd class="{{ $user->hasVerifiedEmail() ? 'is-positive' : 'is-pending' }}"><i class="bi {{ $user->hasVerifiedEmail() ? 'bi-check-circle-fill' : 'bi-exclamation-triangle' }}" aria-hidden="true"></i>{{ $user->hasVerifiedEmail() ? 'Verificado' : 'Pendiente' }}</dd></div>
                        <div><dt>Contraseña</dt><dd>Protegida</dd></div>
                        <div><dt>Autenticación de dos factores</dt><dd class="{{ $user->hasEnabledTwoFactorAuthentication() ? 'is-positive' : 'is-pending' }}"><i class="bi {{ $user->hasEnabledTwoFactorAuthentication() ? 'bi-check-circle-fill' : 'bi-exclamation-triangle' }}" aria-hidden="true"></i>{{ $user->hasEnabledTwoFactorAuthentication() ? 'Activada' : 'No activada' }}</dd></div>
                        <div><dt>Sesiones activas</dt><dd><i class="bi bi-headset" aria-hidden="true"></i>{{ $this->connectedSessions->count() }} {{ $this->connectedSessions->count() === 1 ? 'sesión activa' : 'sesiones activas' }}</dd></div>
                    </dl>
                    <div class="crm-profile-security-actions">
                        <flux:button size="sm" variant="ghost" icon="key" :href="route('security.edit')" wire:navigate>Cambiar contraseña</flux:button>
                        <flux:button size="sm" variant="ghost" icon="shield-check" :href="route('security.edit')" wire:navigate>Configurar 2FA</flux:button>
                        <button type="button" class="crm-profile-text-action" @click="tab = 'security'; $nextTick(() => document.getElementById('dispositivos-cuenta').scrollIntoView({ block: 'center', behavior: 'smooth' }))"><i class="bi bi-display" aria-hidden="true"></i>Ver sesiones activas</button>
                    </div>
                </div>
            </x-profile-panel>

            <x-profile-panel id="dispositivos-cuenta" title="Dispositivos conectados" description="Dispositivos con acceso a tu cuenta." icon="bi-display"
                             x-show="tab === 'general' || tab === 'security'">
                <x-slot:actions>
                    @if (config('session.driver') === 'database')
                        <flux:button size="sm" variant="outline" :disabled="$this->connectedSessions->count() < 2" wire:click="$set('showSessionsModal', true)" class="crm-profile-close-sessions">Cerrar otras sesiones</flux:button>
                    @endif
                </x-slot:actions>
                <div class="crm-profile-session-list">
                    @foreach ($this->connectedSessions as $device)
                        <div class="crm-profile-session" wire:key="profile-session-{{ hash('sha256', $device['id']) }}">
                            <i class="bi {{ $device['icon'] }} crm-profile-browser is-{{ strtolower($device['browser']) }}" aria-hidden="true"></i>
                            <span class="crm-profile-device-name">{{ $device['platform'] }} - {{ $device['browser'] }}</span>
                            @if ($device['current'])<span class="crm-profile-pill is-success">Sesión actual</span>@else<span></span>@endif
                            <span class="crm-profile-device-ip" title="Dirección IP"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ $device['ip'] }}</span>
                            <time>{{ $device['current'] ? 'Ahora' : $device['date']->locale('es')->isoFormat('DD MMM, HH:mm') }}</time>
                        </div>
                    @endforeach
                </div>
            </x-profile-panel>

            <x-profile-panel id="preferencias-cuenta" title="Preferencias de cuenta" description="Personaliza tu experiencia en el sistema." icon="bi-gear"
                             x-show="tab === 'general' || tab === 'preferences'">
                <x-slot:actions><flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('appearance.edit')" wire:navigate class="crm-profile-inline-edit">Editar</flux:button></x-slot:actions>
                <dl class="crm-profile-preference-grid">
                    <div><i class="bi bi-sun" aria-hidden="true"></i><dt>Tema</dt><dd x-text="$flux.appearance === 'dark' ? 'Oscuro' : ($flux.appearance === 'system' ? 'Sistema' : 'Claro')">Claro</dd></div>
                    <div><i class="bi bi-globe2" aria-hidden="true"></i><dt>Idioma</dt><dd>{{ config('app.locale') === 'es' ? 'Español' : config('app.locale') }}</dd></div>
                    <div><i class="bi bi-clock" aria-hidden="true"></i><dt>Zona horaria</dt><dd>(GMT-05:00) Lima</dd></div>
                    <div><i class="bi bi-bell" aria-hidden="true"></i><dt>Notificaciones</dt><dd>{{ $user->hasPermission('notifications.view') ? 'Disponibles' : 'Sin acceso' }}</dd></div>
                </dl>
            </x-profile-panel>

            @if ($this->showDeleteUser)
                <section class="crm-profile-delete" x-show="tab === 'security'" x-cloak>
                    <livewire:pages::settings.delete-user-form />
                </section>
            @endif
        </div>
    </div>

    <flux:modal wire:model="isEditing" class="crm-profile-edit-modal">
        <flux:heading size="lg">Editar perfil</flux:heading>
        <flux:subheading>Información personal y datos de contacto.</flux:subheading>
        <form wire:submit="updateProfileInformation" class="crm-profile-form">
            <div class="crm-profile-photo-field">
                <label for="profile-avatar">Foto de perfil</label>
                <div class="crm-profile-photo-control">
                    <div class="crm-profile-photo-preview">
                        @if ($avatar && in_array($avatar->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true))
                            <img src="{{ $avatar->temporaryUrl() }}" alt="Vista previa de la foto de perfil">
                        @elseif ($user->avatar)
                            <img src="{{ Storage::disk('public')->url($user->avatar) }}" alt="Foto de perfil actual">
                        @else
                            <span>{{ $user->initials() }}</span>
                        @endif
                    </div>
                    <div>
                        <label for="profile-avatar" class="crm-profile-upload-button"><i class="bi bi-camera" aria-hidden="true"></i>Cambiar foto</label>
                        <input id="profile-avatar" type="file" wire:model="avatar" accept="image/jpeg,image/png,image/webp" class="sr-only">
                        <p>JPG, PNG o WEBP. Máximo 2 MB.</p>
                        <span wire:loading wire:target="avatar">Cargando imagen...</span>
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
                <flux:button variant="primary" type="submit" icon="check" data-test="update-profile-button" wire:loading.attr="disabled">Guardar cambios</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal wire:model="showSessionsModal" class="crm-profile-edit-modal">
        <flux:heading size="lg">Cerrar otras sesiones</flux:heading>
        <flux:subheading>Tu sesión actual se mantendrá abierta.</flux:subheading>
        <form wire:submit="closeOtherSessions" class="crm-profile-form">
            <flux:input type="password" wire:model="sessionPassword" label="Contraseña actual" autocomplete="current-password" required />
            <div class="crm-profile-form-actions">
                <flux:button type="button" variant="ghost" wire:click="$set('showSessionsModal', false)">Cancelar</flux:button>
                <flux:button type="submit" variant="primary" icon="arrow-right-start-on-rectangle" wire:loading.attr="disabled">Cerrar otras sesiones</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
