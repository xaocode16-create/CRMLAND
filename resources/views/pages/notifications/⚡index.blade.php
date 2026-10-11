<?php

use App\Models\CrmNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Notificaciones')] class extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $priority = '';

    #[Url]
    public string $dateRange = '';

    #[Url]
    public string $userId = '';

    public string $quickFilter = '';

    public function mount(): void
    {
        Gate::authorize('notifications.view');
        Auth::user()->loadMissing('role');
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->type = '';
        $this->priority = '';
        $this->dateRange = '';
        $this->userId = '';
        $this->quickFilter = '';
    }

    public function quickFilter(string $filter): void
    {
        $this->resetFilters();
        $this->quickFilter = $filter;

        match ($filter) {
            'unread' => $this->status = 'UNREAD',
            'critical' => $this->priority = 'critical',
            'today' => $this->dateRange = 'today',
            'automatic' => null,
            'archived' => $this->status = 'ARCHIVED',
            default => null,
        };
    }

    public function markRead(int $notificationId): void
    {
        $this->ownedNotification($notificationId)->update([
            'status' => 'READ',
            'read_at' => now(),
        ]);
    }

    public function archive(int $notificationId): void
    {
        $this->ownedNotification($notificationId)->update([
            'status' => 'ARCHIVED',
            'read_at' => now(),
        ]);
    }

    public function markAllRead(): void
    {
        CrmNotification::query()
            ->where('user_id', Auth::id())
            ->where('status', 'UNREAD')
            ->update([
                'status' => 'READ',
                'read_at' => now(),
            ]);
    }

    #[Computed]
    public function notifications(): Collection
    {
        $notifications = $this->filteredNotificationsQuery()
            ->latest('created_at')
            ->limit(250)
            ->get();

        if ($this->priority !== '') {
            $notifications = $notifications
                ->filter(fn (CrmNotification $notification): bool => $this->notificationMeta($notification->type)['priority'] === $this->priority)
                ->values();
        }

        if ($this->quickFilter === 'automatic') {
            $notifications = $notifications
                ->filter(fn (CrmNotification $notification): bool => $this->notificationMeta($notification->type)['automatic'])
                ->values();
        }

        return $notifications;
    }

    #[Computed]
    public function groupedNotifications(): Collection
    {
        return $this->notifications->groupBy(fn (CrmNotification $notification): string => $this->dateGroup($notification));
    }

    #[Computed]
    public function metrics(): array
    {
        $notifications = $this->visibleNotificationsQuery()
            ->get(['id', 'type', 'status', 'created_at']);

        return [
            'unread' => $notifications->where('status', 'UNREAD')->count(),
            'critical' => $notifications
                ->filter(fn (CrmNotification $notification): bool => $notification->status === 'UNREAD' && $this->notificationMeta($notification->type)['priority'] === 'critical')
                ->count(),
            'today' => $notifications
                ->filter(fn (CrmNotification $notification): bool => $notification->created_at?->isToday() === true)
                ->count(),
            'automatic' => $notifications
                ->filter(fn (CrmNotification $notification): bool => $this->notificationMeta($notification->type)['automatic'])
                ->count(),
            'archived' => $notifications->where('status', 'ARCHIVED')->count(),
        ];
    }

    #[Computed]
    public function typeOptions(): array
    {
        $types = [
            'LEAD_ASSIGNED',
            'LEAD_CREATED',
            'FOLLOW_UP_DUE',
            'RESERVATION_EXPIRING',
            'SALE_REGISTERED',
            'SALE_PENDING_SIGNATURE',
            'USER_INVITATION',
            'SECURITY_ALERT',
            'PROPERTY_MISSING_PHOTOS',
            'COMMISSION_PENDING',
            'SYSTEM_UPDATE',
        ];

        $databaseTypes = $this->visibleNotificationsQuery()
            ->select('type')
            ->distinct()
            ->orderBy('type')
            ->pluck('type')
            ->all();

        return collect([...$types, ...$databaseTypes])
            ->filter()
            ->unique()
            ->mapWithKeys(fn (string $notificationType): array => [
                $notificationType => $this->notificationMeta($notificationType)['label'],
            ])
            ->all();
    }

    #[Computed]
    public function users(): Collection
    {
        $user = Auth::user();
        $query = User::query()->with('role')->where('status', 'active')->orderBy('name');

        if ($user->isAdministrador()) {
            return $query->get();
        }

        if ($user->isSupervisor()) {
            return $query
                ->where(fn (Builder $teamQuery): Builder => $teamQuery
                    ->whereKey($user->getKey())
                    ->orWhere('supervisor_user_id', $user->getKey()))
                ->get();
        }

        return $query->whereKey($user->getKey())->get();
    }

    #[Computed]
    public function alerts(): Collection
    {
        return $this->visibleNotificationsQuery()
            ->with(['lead', 'user.role'])
            ->where('status', 'UNREAD')
            ->latest('created_at')
            ->limit(100)
            ->get()
            ->filter(fn (CrmNotification $notification): bool => in_array($this->notificationMeta($notification->type)['priority'], ['critical', 'high'], true))
            ->take(4)
            ->values();
    }

    #[Computed]
    public function roleScope(): array
    {
        $user = Auth::user();

        return match (true) {
            $user->isAdministrador() => ['label' => 'Administrador', 'description' => 'Todas las alertas del sistema y del equipo'],
            $user->isSupervisor() => ['label' => 'Supervisor', 'description' => 'Alertas propias y de tu equipo asignado'],
            default => ['label' => 'Asesor', 'description' => 'Alertas y recordatorios de tu operación'],
        };
    }

    public function notificationMeta(?string $type): array
    {
        $key = Str::upper((string) $type);

        if (Str::contains($key, ['SECURITY', 'SEGURIDAD'])) {
            return ['label' => 'Alerta de seguridad', 'module' => 'Sistema', 'icon' => 'bi-shield-fill-exclamation', 'tone' => 'red', 'priority' => 'critical', 'priorityLabel' => 'Crítica', 'automatic' => true];
        }

        if (Str::contains($key, ['RESERVATION', 'RESERVA', 'EXPIR'])) {
            return ['label' => 'Reserva por vencer', 'module' => 'Reservas', 'icon' => 'bi-alarm', 'tone' => 'red', 'priority' => 'critical', 'priorityLabel' => 'Crítica', 'automatic' => true];
        }

        if (Str::contains($key, ['FOLLOW', 'SEGUIMIENTO'])) {
            return ['label' => 'Seguimiento vencido', 'module' => 'Seguimientos', 'icon' => 'bi-check2-circle', 'tone' => 'green', 'priority' => 'high', 'priorityLabel' => 'Alta', 'automatic' => true];
        }

        if (Str::contains($key, ['LEAD'])) {
            return ['label' => 'Nuevo lead asignado', 'module' => 'Leads', 'icon' => 'bi-person-plus-fill', 'tone' => 'blue', 'priority' => 'high', 'priorityLabel' => 'Alta', 'automatic' => false];
        }

        if (Str::contains($key, ['SALE', 'VENTA', 'SIGNATURE', 'FIRMA'])) {
            return ['label' => 'Documento pendiente de firma', 'module' => 'Ventas', 'icon' => 'bi-file-earmark-text-fill', 'tone' => 'purple', 'priority' => 'high', 'priorityLabel' => 'Alta', 'automatic' => false];
        }

        if (Str::contains($key, ['USER', 'USUARIO', 'INVIT'])) {
            return ['label' => 'Invitación de usuario pendiente', 'module' => 'Usuarios', 'icon' => 'bi-people-fill', 'tone' => 'amber', 'priority' => 'medium', 'priorityLabel' => 'Media', 'automatic' => false];
        }

        if (Str::contains($key, ['PROPERTY', 'PROPIEDAD', 'PHOTO', 'FOTO'])) {
            return ['label' => 'Propiedad requiere información', 'module' => 'Propiedades', 'icon' => 'bi-images', 'tone' => 'purple', 'priority' => 'medium', 'priorityLabel' => 'Media', 'automatic' => false];
        }

        if (Str::contains($key, ['COMMISSION', 'COMISION'])) {
            return ['label' => 'Comisión pendiente de aprobación', 'module' => 'Comisiones', 'icon' => 'bi-percent', 'tone' => 'purple', 'priority' => 'medium', 'priorityLabel' => 'Media', 'automatic' => false];
        }

        return ['label' => Str::headline(str_replace(['_', '-'], ' ', $key ?: 'Notificación')), 'module' => 'Sistema', 'icon' => 'bi-megaphone-fill', 'tone' => 'slate', 'priority' => 'low', 'priorityLabel' => 'Baja', 'automatic' => true];
    }

    public function priorityClass(string $priority): string
    {
        return match ($priority) {
            'critical' => 'is-critical',
            'high' => 'is-high',
            'medium' => 'is-medium',
            default => 'is-low',
        };
    }

    public function isOwner(CrmNotification $notification): bool
    {
        return (int) $notification->user_id === (int) Auth::id();
    }

    private function filteredNotificationsQuery(): Builder
    {
        return $this->visibleNotificationsQuery()
            ->with(['lead', 'user.role'])
            ->when($this->status !== '', fn (Builder $query): Builder => $query->where('status', $this->status))
            ->when($this->type !== '', fn (Builder $query): Builder => $query->where('type', $this->type))
            ->when($this->userId !== '', fn (Builder $query): Builder => $query->where('user_id', (int) $this->userId))
            ->when($this->search !== '', function (Builder $query): void {
                $search = '%'.$this->search.'%';
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery
                        ->where('title', 'like', $search)
                        ->orWhere('body', 'like', $search)
                        ->orWhere('type', 'like', $search)
                        ->orWhereHas('user', fn (Builder $userQuery): Builder => $userQuery->where('name', 'like', $search)->orWhere('email', 'like', $search))
                        ->orWhereHas('lead', fn (Builder $leadQuery): Builder => $leadQuery->where('full_name', 'like', $search));
                });
            })
            ->when($this->dateRange === 'today', fn (Builder $query): Builder => $query->whereDate('created_at', today()))
            ->when($this->dateRange === 'week', fn (Builder $query): Builder => $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]))
            ->when($this->dateRange === 'month', fn (Builder $query): Builder => $query->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()]))
            ->when($this->quickFilter === 'automatic', fn (Builder $query): Builder => $query->where(function (Builder $automaticQuery): void {
                $automaticQuery
                    ->where('type', 'like', '%SYSTEM%')
                    ->orWhere('type', 'like', '%SECURITY%')
                    ->orWhere('type', 'like', '%RESERVATION%')
                    ->orWhere('type', 'like', '%FOLLOW%');
            }));
    }

    private function visibleNotificationsQuery(): Builder
    {
        $user = Auth::user();
        $query = CrmNotification::query();

        if ($user->isAdministrador()) {
            return $query;
        }

        if ($user->isSupervisor()) {
            return $query->whereIn('user_id', User::query()
                ->whereKey($user->getKey())
                ->orWhere('supervisor_user_id', $user->getKey())
                ->pluck('id'));
        }

        return $query->where('user_id', $user->getKey());
    }

    private function ownedNotification(int $notificationId): CrmNotification
    {
        return CrmNotification::query()
            ->where('user_id', Auth::id())
            ->whereKey($notificationId)
            ->firstOrFail();
    }

    private function dateGroup(CrmNotification $notification): string
    {
        if ($notification->created_at?->isToday()) {
            return 'Hoy';
        }

        if ($notification->created_at?->isYesterday()) {
            return 'Ayer';
        }

        return $notification->created_at?->translatedFormat('l, d \d\e F \d\e Y') ?? 'Sin fecha';
    }
};
?>

<section class="crm-notifications-page">
    <header class="crm-card crm-notifications-header">
        <div>
            <p class="crm-eyebrow">Administración</p>
            <h1 class="crm-module-title">Notificaciones</h1>
            <p class="crm-module-description">Centraliza alertas, recordatorios, aprobaciones y mensajes importantes de tu operación.</p>
            <p class="crm-notifications-scope"><i class="bi bi-shield-check"></i>{{ $this->roleScope['label'] }} · {{ $this->roleScope['description'] }}</p>
        </div>
        <div class="crm-notifications-header-actions">
            <a href="{{ route('appearance.edit') }}" class="crm-notifications-secondary-button" wire:navigate><i class="bi bi-sliders"></i> Preferencias</a>
            <button type="button" class="crm-notifications-primary-button" wire:click="markAllRead"><i class="bi bi-check2-circle"></i> Marcar todo leído</button>
        </div>
    </header>

    <section class="crm-notifications-metrics">
        <button type="button" class="crm-card crm-notifications-metric is-blue" wire:click="quickFilter('unread')"><span class="crm-notifications-metric-icon"><i class="bi bi-envelope"></i></span><span><strong>{{ $this->metrics['unread'] }}</strong><small>No leídas</small></span><i class="bi bi-chevron-right"></i></button>
        <button type="button" class="crm-card crm-notifications-metric is-red" wire:click="quickFilter('critical')"><span class="crm-notifications-metric-icon"><i class="bi bi-exclamation-triangle"></i></span><span><strong>{{ $this->metrics['critical'] }}</strong><small>Críticas</small></span><i class="bi bi-chevron-right"></i></button>
        <button type="button" class="crm-card crm-notifications-metric is-green" wire:click="quickFilter('today')"><span class="crm-notifications-metric-icon"><i class="bi bi-calendar-check"></i></span><span><strong>{{ $this->metrics['today'] }}</strong><small>Hoy</small></span><i class="bi bi-chevron-right"></i></button>
        <button type="button" class="crm-card crm-notifications-metric is-purple" wire:click="quickFilter('automatic')"><span class="crm-notifications-metric-icon"><i class="bi bi-gear"></i></span><span><strong>{{ $this->metrics['automatic'] }}</strong><small>Automáticas</small></span><i class="bi bi-chevron-right"></i></button>
        <button type="button" class="crm-card crm-notifications-metric is-slate" wire:click="quickFilter('archived')"><span class="crm-notifications-metric-icon"><i class="bi bi-archive"></i></span><span><strong>{{ $this->metrics['archived'] }}</strong><small>Archivadas</small></span><i class="bi bi-chevron-right"></i></button>
    </section>

    <section class="crm-card crm-notifications-filters">
        <div class="crm-notifications-filter-heading"><div><h2><i class="bi bi-funnel"></i> Filtros de notificaciones</h2><p>Encuentra alertas por estado, módulo, prioridad, fecha o responsable.</p></div><button type="button" wire:click="resetFilters"><i class="bi bi-arrow-counterclockwise"></i> Limpiar filtros</button></div>
        <div class="crm-notifications-filter-grid">
            <label class="crm-notifications-search"><span>Buscar</span><div><i class="bi bi-search"></i><input type="search" wire:model.live.debounce.350ms="search" placeholder="Buscar notificaciones..."></div></label>
            <label><span>Estado</span><select wire:model.live="status"><option value="">Todos</option><option value="UNREAD">No leídas</option><option value="READ">Leídas</option><option value="ARCHIVED">Archivadas</option></select></label>
            <label><span>Tipo</span><select wire:model.live="type"><option value="">Todos</option>@foreach ($this->typeOptions as $optionValue => $optionLabel)<option value="{{ $optionValue }}">{{ $optionLabel }}</option>@endforeach</select></label>
            <label><span>Prioridad</span><select wire:model.live="priority"><option value="">Todas</option><option value="critical">Crítica</option><option value="high">Alta</option><option value="medium">Media</option><option value="low">Baja</option></select></label>
            <label><span>Fecha</span><select wire:model.live="dateRange"><option value="">Cualquier fecha</option><option value="today">Hoy</option><option value="week">Esta semana</option><option value="month">Este mes</option></select></label>
            @if ($this->users->count() > 1)
                <label><span>Usuario / área</span><select wire:model.live="userId"><option value="">Todos</option>@foreach ($this->users as $user)<option value="{{ $user->id }}">{{ $user->name }} · {{ $user->role?->name }}</option>@endforeach</select></label>
            @endif
        </div>
    </section>

    <div class="crm-notifications-layout">
        <section class="crm-card crm-notifications-list-card">
            <div class="crm-notifications-list-heading"><div><h2>Centro de notificaciones <span>{{ $this->notifications->count() }}</span></h2><p>Selecciona una alerta para revisar su detalle y contexto.</p></div><span class="crm-notifications-current-date">{{ now()->translatedFormat('l, d \d\e F \d\e Y') }}</span></div>
            @forelse ($this->groupedNotifications as $group => $groupNotifications)
                <div class="crm-notifications-day"><div class="crm-notifications-day-heading"><h3>{{ $group }} <span>({{ $groupNotifications->count() }})</span></h3><span>{{ $groupNotifications->first()->created_at?->format('d/m/Y') }}</span></div></div>
                @foreach ($groupNotifications as $notification)
                    @php($meta = $this->notificationMeta($notification->type))
                    <article class="crm-notification-row {{ $notification->status === 'UNREAD' ? 'is-unread' : '' }}" wire:key="notification-{{ $notification->id }}">
                        <input type="checkbox" aria-label="Seleccionar notificación">
                        <span class="crm-notification-unread-dot"></span>
                        <span class="crm-notification-icon crm-notification-icon--{{ $meta['tone'] }}"><i class="bi {{ $meta['icon'] }}"></i></span>
                        <div class="crm-notification-content"><div class="crm-notification-title-line"><strong>{{ $notification->title ?: $meta['label'] }}</strong><span class="crm-notification-module"><i class="bi bi-grid"></i>{{ $meta['module'] }}</span></div><p>{{ $notification->body ?: 'Tienes una actualización pendiente en el sistema.' }}</p><div class="crm-notification-context"><span><i class="bi bi-person"></i>{{ $notification->user?->name ?? 'Sistema' }}</span>@if ($notification->lead)<a href="{{ route('leads.show', $notification->lead) }}" wire:navigate><i class="bi bi-person-vcard"></i>{{ $notification->lead->full_name }}</a>@endif<span><i class="bi bi-clock"></i>{{ $notification->created_at?->diffForHumans() }}</span></div></div>
                        <span class="crm-notification-priority {{ $this->priorityClass($meta['priority']) }}">{{ $meta['priorityLabel'] }}</span>
                        <div class="crm-notification-actions"><button type="button" title="Marcar como leída" @disabled(! $this->isOwner($notification) || $notification->status !== 'UNREAD') wire:click="markRead({{ $notification->id }})"><i class="bi bi-check-circle"></i></button>@if ($this->isOwner($notification) && $notification->status !== 'ARCHIVED')<button type="button" title="Archivar" wire:click="archive({{ $notification->id }})"><i class="bi bi-archive"></i></button>@endif</div>
                    </article>
                @endforeach
            @empty
                <div class="crm-notifications-empty"><span><i class="bi bi-bell-slash"></i></span><strong>No hay notificaciones con estos filtros.</strong><p>Prueba con otros criterios o limpia los filtros para ver más actividad.</p><button type="button" wire:click="resetFilters">Limpiar filtros</button></div>
            @endforelse
        </section>

        <aside class="crm-notifications-sidebar">
            <section class="crm-card crm-notifications-side-card"><div class="crm-notifications-side-heading"><h2><i class="bi bi-exclamation-triangle"></i> Alertas prioritarias</h2><span>{{ $this->alerts->count() }}</span></div>@forelse ($this->alerts as $alert) @php($alertMeta = $this->notificationMeta($alert->type))<button type="button" class="crm-notifications-alert-row" @disabled(! $this->isOwner($alert) || $alert->status !== 'UNREAD') wire:click="markRead({{ $alert->id }})"><span class="crm-notification-icon crm-notification-icon--{{ $alertMeta['tone'] }}"><i class="bi {{ $alertMeta['icon'] }}"></i></span><span><strong>{{ $alert->title ?: $alertMeta['label'] }}</strong><small>{{ $alert->body ?: 'Requiere revisión.' }}</small></span><em class="{{ $this->priorityClass($alertMeta['priority']) }}">{{ $alertMeta['priorityLabel'] }}</em></button>@empty<p class="crm-notifications-side-empty">No hay alertas prioritarias.</p>@endforelse</section>
            <section class="crm-card crm-notifications-side-card"><div class="crm-notifications-side-heading"><h2><i class="bi bi-bar-chart"></i> Resumen de acciones</h2><select wire:model.live="dateRange"><option value="">Todo</option><option value="today">Hoy</option><option value="week">Semana</option><option value="month">Mes</option></select></div><div class="crm-notifications-summary-list"><button type="button" wire:click="quickFilter('unread')"><i class="bi bi-envelope"></i><strong>{{ $this->metrics['unread'] }}</strong><span>Notificaciones no leídas</span></button><button type="button" wire:click="quickFilter('critical')"><i class="bi bi-clock-history"></i><strong>{{ $this->metrics['critical'] }}</strong><span>Requieren tu acción</span></button><button type="button" wire:click="quickFilter('today')"><i class="bi bi-calendar-check"></i><strong>{{ $this->metrics['today'] }}</strong><span>Recordatorios de hoy</span></button><button type="button" wire:click="quickFilter('automatic')"><i class="bi bi-person-plus"></i><strong>{{ $this->metrics['automatic'] }}</strong><span>Alertas automáticas</span></button></div></section>
            <section class="crm-card crm-notifications-side-card"><div class="crm-notifications-side-heading"><h2><i class="bi bi-gear"></i> Reglas automáticas</h2><i class="bi bi-chevron-right"></i></div><p class="crm-notifications-side-description">Gestiona cómo se generan las alertas de tu operación.</p><div class="crm-notifications-rules"><div><span class="crm-notification-rule-icon is-purple"><i class="bi bi-person-plus"></i></span><span><strong>Lead asignado</strong><small>Notificación inmediata</small></span><i class="bi bi-chevron-right"></i></div><div><span class="crm-notification-rule-icon is-red"><i class="bi bi-alarm"></i></span><span><strong>Reserva por vencer</strong><small>24 horas antes</small></span><i class="bi bi-chevron-right"></i></div><div><span class="crm-notification-rule-icon is-green"><i class="bi bi-check2-circle"></i></span><span><strong>Seguimiento vencido</strong><small>Diaria a las 09:00</small></span><i class="bi bi-chevron-right"></i></div></div></section>
            <section class="crm-card crm-notifications-side-card"><div class="crm-notifications-side-heading"><h2><i class="bi bi-bell"></i> Preferencias</h2><a href="{{ route('appearance.edit') }}" wire:navigate>Configurar <i class="bi bi-arrow-right"></i></a></div><p class="crm-notifications-side-description">Elige cómo recibir las notificaciones de tu cuenta.</p><div class="crm-notifications-preferences"><div><i class="bi bi-bell"></i><span><strong>Notificaciones en la aplicación</strong><small>Alertas dentro del sistema</small></span><span class="crm-notifications-switch is-on"></span></div><div><i class="bi bi-envelope"></i><span><strong>Notificaciones por email</strong><small>Resumen de actividad</small></span><span class="crm-notifications-switch is-on"></span></div><div><i class="bi bi-phone"></i><span><strong>Notificaciones push</strong><small>En tu navegador o dispositivo</small></span><span class="crm-notifications-switch is-on"></span></div></div><a class="crm-notifications-preferences-link" href="{{ route('appearance.edit') }}" wire:navigate>Configurar preferencias avanzadas <i class="bi bi-arrow-right"></i></a></section>
        </aside>
    </div>
</section>
