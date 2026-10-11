<?php

use App\Enums\CommissionStatus;
use App\Enums\FollowUpStatus;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Commission;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Panel')] class extends Component
{
    public function mount(): void
    {
        $this->currentUser()->loadMissing('role.permissions');
    }

    /**
     * @return array<int, array{key: string, label: string, value: string, raw: int|float, icon: string, tone: string, trend: array{value: string, positive: bool}}>
     */
    #[Computed]
    public function dashboardStats(): array
    {
        $user = $this->currentUser();
        $period = $this->monthWindows();

        $activeLeads = Lead::query()->visibleFor($user)->where('status', 'active')->count();
        $newLeads = Lead::query()->visibleFor($user)
            ->whereBetween('entered_at', [$period['currentStart'], $period['currentEnd']])
            ->count();
        $previousNewLeads = Lead::query()->visibleFor($user)
            ->whereBetween('entered_at', [$period['previousStart'], $period['previousEnd']])
            ->count();

        $visibleProperties = Property::query()->visibleFor($user)->where('status', 'AVAILABLE')->count();
        $newProperties = Property::query()->visibleFor($user)
            ->whereBetween('created_at', [$period['currentStart'], $period['currentEnd']])
            ->count();
        $previousNewProperties = Property::query()->visibleFor($user)
            ->whereBetween('created_at', [$period['previousStart'], $period['previousEnd']])
            ->count();

        $todayFollowUps = FollowUp::query()->visibleFor($user)
            ->where('status', FollowUpStatus::Pending->value)
            ->whereDate('scheduled_at', today())
            ->count();
        $monthlyFollowUps = FollowUp::query()->visibleFor($user)
            ->where('status', FollowUpStatus::Pending->value)
            ->whereBetween('scheduled_at', [$period['currentStart'], $period['currentEnd']])
            ->count();
        $previousMonthlyFollowUps = FollowUp::query()->visibleFor($user)
            ->where('status', FollowUpStatus::Pending->value)
            ->whereBetween('scheduled_at', [$period['previousStart'], $period['previousEnd']])
            ->count();

        $activeReservations = Reservation::query()->visibleFor($user)->where('status', 'ACTIVE')->count();
        $monthlyReservations = Reservation::query()->visibleFor($user)
            ->whereBetween('reserved_at', [$period['currentStart'], $period['currentEnd']])
            ->count();
        $previousMonthlyReservations = Reservation::query()->visibleFor($user)
            ->whereBetween('reserved_at', [$period['previousStart'], $period['previousEnd']])
            ->count();

        $monthlySales = Sale::query()->visibleFor($user)
            ->whereNot('status', SaleStatus::Cancelled->value)
            ->whereBetween('sold_at', [$period['currentStart'], $period['currentEnd']])
            ->count();
        $previousMonthlySales = Sale::query()->visibleFor($user)
            ->whereNot('status', SaleStatus::Cancelled->value)
            ->whereBetween('sold_at', [$period['previousStart'], $period['previousEnd']])
            ->count();

        $pendingCommissions = Commission::query()->visibleFor($user)
            ->where('status', CommissionStatus::Pending->value)
            ->sum('amount');
        $monthlyCommissions = Commission::query()->visibleFor($user)
            ->whereNot('status', CommissionStatus::Cancelled->value)
            ->whereBetween('created_at', [$period['currentStart'], $period['currentEnd']])
            ->sum('amount');
        $previousMonthlyCommissions = Commission::query()->visibleFor($user)
            ->whereNot('status', CommissionStatus::Cancelled->value)
            ->whereBetween('created_at', [$period['previousStart'], $period['previousEnd']])
            ->sum('amount');

        return [
            [
                'key' => 'leads',
                'label' => 'Leads activos',
                'value' => number_format($activeLeads),
                'raw' => $activeLeads,
                'icon' => 'bi-people',
                'tone' => 'blue',
                'trend' => $this->trend($newLeads, $previousNewLeads),
            ],
            [
                'key' => 'properties',
                'label' => 'Propiedades visibles',
                'value' => number_format($visibleProperties),
                'raw' => $visibleProperties,
                'icon' => 'bi-house-door',
                'tone' => 'cyan',
                'trend' => $this->trend($newProperties, $previousNewProperties),
            ],
            [
                'key' => 'followups',
                'label' => 'Seguimientos hoy',
                'value' => number_format($todayFollowUps),
                'raw' => $todayFollowUps,
                'icon' => 'bi-calendar-check',
                'tone' => 'orange',
                'trend' => $this->trend($monthlyFollowUps, $previousMonthlyFollowUps),
            ],
            [
                'key' => 'reservations',
                'label' => 'Reservas activas',
                'value' => number_format($activeReservations),
                'raw' => $activeReservations,
                'icon' => 'bi-bookmark-star',
                'tone' => 'violet',
                'trend' => $this->trend($monthlyReservations, $previousMonthlyReservations),
            ],
            [
                'key' => 'sales',
                'label' => 'Ventas del mes',
                'value' => number_format($monthlySales),
                'raw' => $monthlySales,
                'icon' => 'bi-cash-stack',
                'tone' => 'green',
                'trend' => $this->trend($monthlySales, $previousMonthlySales),
            ],
            [
                'key' => 'commissions',
                'label' => 'Comisiones pendientes',
                'value' => 'S/ '.number_format((float) $pendingCommissions, 0),
                'raw' => (float) $pendingCommissions,
                'icon' => 'bi-percent',
                'tone' => 'red',
                'trend' => $this->trend((float) $monthlyCommissions, (float) $previousMonthlyCommissions),
            ],
        ];
    }

    /**
     * Preserve the original dashboard data contract for existing consumers.
     *
     * @return array{leads: int, propiedades: int, seguimientos: int, ventas: int, comisiones: float}
     */
    #[Computed]
    public function stats(): array
    {
        $cards = collect($this->dashboardStats)->keyBy('key');

        return [
            'leads' => (int) $cards->get('leads')['raw'],
            'propiedades' => (int) $cards->get('properties')['raw'],
            'seguimientos' => (int) $cards->get('followups')['raw'],
            'ventas' => (int) $cards->get('sales')['raw'],
            'comisiones' => (float) $cards->get('commissions')['raw'],
        ];
    }

    /**
     * @return array{slug: string, name: string, description: string, scope: string}
     */
    #[Computed]
    public function activeRole(): array
    {
        $user = $this->currentUser();
        $role = $user->role;

        if ($role === null) {
            return [
                'slug' => UserRole::Asesor->value,
                'name' => 'Asesor',
                'description' => 'Gestiona sus leads, clientes y oportunidades asignadas.',
                'scope' => 'Vista personal',
            ];
        }

        return [
            'slug' => $role->slug,
            'name' => $role->name,
            'description' => $role->description ?? '',
            'scope' => match ($role->slug) {
                UserRole::Administrador->value => 'Toda la operación',
                UserRole::Supervisor->value => 'Equipo asignado',
                default => 'Actividad personal',
            },
        ];
    }

    /**
     * @return Collection<int, array{label: string, leads: int, reservations: int, sales: int}>
     */
    #[Computed]
    public function commercialEvolution(): Collection
    {
        $user = $this->currentUser();
        $start = CarbonImmutable::now()->startOfMonth()->subMonths(5);
        $end = CarbonImmutable::now();

        $leadCounts = Lead::query()->visibleFor($user)
            ->whereBetween('entered_at', [$start, $end])
            ->get(['entered_at'])
            ->groupBy(fn (Lead $lead): string => $lead->entered_at->format('Y-m'))
            ->map->count();

        $reservationCounts = Reservation::query()->visibleFor($user)
            ->whereNot('status', 'CANCELLED')
            ->whereBetween('reserved_at', [$start, $end])
            ->get(['reserved_at'])
            ->groupBy(fn (Reservation $reservation): string => $reservation->reserved_at->format('Y-m'))
            ->map->count();

        $saleCounts = Sale::query()->visibleFor($user)
            ->whereNot('status', SaleStatus::Cancelled->value)
            ->whereBetween('sold_at', [$start, $end])
            ->get(['sold_at'])
            ->groupBy(fn (Sale $sale): string => $sale->sold_at->format('Y-m'))
            ->map->count();

        return collect(range(0, 5))->map(function (int $index) use ($start, $leadCounts, $reservationCounts, $saleCounts): array {
            $month = $start->addMonths($index);
            $key = $month->format('Y-m');

            return [
                'label' => ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'][$month->month - 1],
                'leads' => (int) $leadCounts->get($key, 0),
                'reservations' => (int) $reservationCounts->get($key, 0),
                'sales' => (int) $saleCounts->get($key, 0),
            ];
        });
    }

    /**
     * @return Collection<int, array{name: string, count: int, percentage: int, color: string}>
     */
    #[Computed]
    public function pipeline(): Collection
    {
        $user = $this->currentUser();
        $statuses = LeadStatus::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->withCount([
                'leads as visible_leads_count' => fn (Builder $query) => $query->visibleFor($user),
            ])
            ->get();
        $total = max(1, (int) $statuses->sum('visible_leads_count'));

        return $statuses->map(fn (LeadStatus $status): array => [
            'name' => $status->name,
            'count' => (int) $status->visible_leads_count,
            'percentage' => (int) round(((int) $status->visible_leads_count / $total) * 100),
            'color' => $this->safeColor($status->color),
        ]);
    }

    /**
     * @return Collection<int, array{name: string, slug: string, description: string, icon: string, is_current: bool, permissions: array<int, string>, permission_count: int}>
     */
    #[Computed]
    public function roleCards(): Collection
    {
        $user = $this->currentUser();
        $activeRoleSlug = $this->activeRole['slug'];
        $allowedSlugs = match (true) {
            $user->isAdministrador() => [UserRole::Administrador->value, UserRole::Supervisor->value, UserRole::Asesor->value],
            $user->isSupervisor() => [UserRole::Supervisor->value, UserRole::Asesor->value],
            default => [UserRole::Asesor->value],
        };

        return Role::query()
            ->with('permissions:id,name,module')
            ->whereIn('slug', $allowedSlugs)
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role): array => [
                'name' => $role->name,
                'slug' => $role->slug,
                'description' => $role->description ?? '',
                'icon' => match ($role->slug) {
                    UserRole::Administrador->value => 'bi-crown',
                    UserRole::Supervisor->value => 'bi-people',
                    default => 'bi-person',
                },
                'is_current' => $role->slug === $activeRoleSlug,
                'permissions' => $role->permissions->pluck('name')->take(5)->values()->all(),
                'permission_count' => $role->permissions->count(),
            ]);
    }

    #[Computed]
    public function recentLeads(): Collection
    {
        return Lead::query()
            ->visibleFor($this->currentUser())
            ->with(['source', 'pipelineStatus', 'assignedUser'])
            ->latest('entered_at')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function recentProperties(): Collection
    {
        return Property::query()
            ->visibleFor($this->currentUser())
            ->with('type')
            ->latest()
            ->limit(6)
            ->get(['id', 'title', 'code', 'property_type_id', 'status', 'created_at']);
    }

    #[Computed]
    public function todayFollowUps(): Collection
    {
        return FollowUp::query()
            ->visibleFor($this->currentUser())
            ->with(['lead:id,full_name,phone', 'assignedUser:id,name'])
            ->where('status', FollowUpStatus::Pending->value)
            ->whereDate('scheduled_at', today())
            ->orderBy('scheduled_at')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function recentActivities(): Collection
    {
        return LeadActivity::query()
            ->whereHas('lead', fn (Builder $query) => $query->visibleFor($this->currentUser()))
            ->with(['lead:id,full_name', 'user:id,name'])
            ->latest()
            ->limit(6)
            ->get();
    }

    /**
     * @return array<int, array{label: string, description: string, value: int, icon: string, tone: string, href: string}>
     */
    #[Computed]
    public function alerts(): array
    {
        $user = $this->currentUser();

        return [
            [
                'label' => $user->isAsesor() ? 'Mis reservas pendientes' : 'Reservas pendientes',
                'description' => 'Esperan aprobación',
                'value' => Reservation::query()->visibleFor($user)
                    ->where('requires_approval', true)
                    ->where('approval_status', 'PENDING')
                    ->count(),
                'icon' => 'bi-exclamation-circle',
                'tone' => 'red',
                'href' => route('reservations.index'),
            ],
            [
                'label' => 'Por vencer hoy',
                'description' => 'Requieren seguimiento',
                'value' => Reservation::query()->visibleFor($user)
                    ->where('status', 'ACTIVE')
                    ->whereDate('expires_at', today())
                    ->count(),
                'icon' => 'bi-clock-history',
                'tone' => 'orange',
                'href' => route('reservations.index'),
            ],
            [
                'label' => 'Leads sin seguimiento',
                'description' => 'Sin próxima actividad registrada',
                'value' => Lead::query()->visibleFor($user)
                    ->where('status', 'active')
                    ->whereNull('next_follow_up_at')
                    ->count(),
                'icon' => 'bi-person-exclamation',
                'tone' => 'blue',
                'href' => route('leads.index'),
            ],
            [
                'label' => 'Propiedades sin fotos',
                'description' => 'Completa la información comercial',
                'value' => Property::query()->visibleFor($user)
                    ->whereDoesntHave('images')
                    ->count(),
                'icon' => 'bi-image',
                'tone' => 'violet',
                'href' => route('properties.index'),
            ],
        ];
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /**
     * @return array{currentStart: CarbonImmutable, currentEnd: CarbonImmutable, previousStart: CarbonImmutable, previousEnd: CarbonImmutable}
     */
    private function monthWindows(): array
    {
        $currentStart = CarbonImmutable::now()->startOfMonth();

        return [
            'currentStart' => $currentStart,
            'currentEnd' => CarbonImmutable::now(),
            'previousStart' => $currentStart->subMonthNoOverflow(),
            'previousEnd' => $currentStart->subSecond(),
        ];
    }

    /**
     * @return array{value: string, positive: bool}
     */
    private function trend(int|float $current, int|float $previous): array
    {
        $change = $previous == 0
            ? ($current > 0 ? 100 : 0)
            : (($current - $previous) / $previous) * 100;

        return [
            'value' => ($change >= 0 ? '+' : '').number_format($change, 0).'%',
            'positive' => $change >= 0,
        ];
    }

    private function safeColor(?string $color): string
    {
        return is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1
            ? $color
            : '#1683e8';
    }
};
?>

<section class="crm-page crm-dashboard-page">
    <header class="crm-card crm-dashboard-hero">
        <div class="crm-dashboard-hero__copy">
            <p class="crm-eyebrow">Resumen general</p>
            <h1 class="crm-module-title">Panel principal</h1>
            <p class="crm-module-description">Actividad actual del equipo y oportunidades recientes.</p>
            <p class="crm-dashboard-hero__scope">{{ $this->activeRole['scope'] }} · Datos actualizados desde la base de datos</p>
        </div>
        <div class="crm-dashboard-hero__roles">
            <span>Rol activo:</span>
            <div class="crm-dashboard-role-switcher" aria-label="Rol activo">
                @foreach ($this->roleCards as $role)
                    <span class="crm-dashboard-role-pill {{ $role['is_current'] ? 'is-active' : '' }}" wire:key="dashboard-role-{{ $role['slug'] }}">
                        <i class="bi {{ $role['icon'] }}" aria-hidden="true"></i>
                        {{ $role['name'] }}
                    </span>
                @endforeach
            </div>
        </div>
        <div class="crm-dashboard-hero__visual" aria-hidden="true">
            <img src="{{ asset('assets/img/profile-building.jpg') }}" alt="">
        </div>
    </header>

    <div class="crm-grid crm-grid--stats crm-dashboard-stats">
        @foreach ($this->dashboardStats as $stat)
            <article class="crm-card crm-dashboard-stat crm-dashboard-stat--{{ $stat['tone'] }}" wire:key="dashboard-stat-{{ $stat['key'] }}">
                <div class="crm-dashboard-stat__icon"><i class="bi {{ $stat['icon'] }}" aria-hidden="true"></i></div>
                <span>{{ $stat['label'] }}</span>
                <strong>{{ $stat['value'] }}</strong>
                <p class="crm-dashboard-stat__trend {{ $stat['trend']['positive'] ? 'is-positive' : 'is-negative' }}">
                    <i class="bi {{ $stat['trend']['positive'] ? 'bi-arrow-up-right' : 'bi-arrow-down-right' }}" aria-hidden="true"></i>
                    {{ $stat['trend']['value'] }}
                    <small>vs. mes anterior</small>
                </p>
            </article>
        @endforeach
    </div>

    <div class="crm-dashboard-main-grid">
        <section class="crm-card crm-dashboard-chart-card">
            <div class="crm-dashboard-section-heading">
                <div>
                    <p class="crm-eyebrow">Evolución comercial</p>
                    <h2 class="crm-section-title">Actividad de los últimos 6 meses</h2>
                    <p class="crm-module-description">Leads, reservas y ventas registradas en tu alcance.</p>
                </div>
                <span class="crm-dashboard-filter-label">Últimos 6 meses</span>
            </div>
            @php($evolutionMax = max(1, (int) $this->commercialEvolution->flatMap(fn (array $month): array => [$month['leads'], $month['reservations'], $month['sales']])->max()))
            <div class="crm-dashboard-chart" aria-label="Evolución comercial de los últimos seis meses">
                <div class="crm-dashboard-chart__scale"><span>{{ $evolutionMax }}</span><span>{{ (int) round($evolutionMax / 2) }}</span><span>0</span></div>
                <div class="crm-dashboard-chart__plot">
                    <div class="crm-dashboard-chart__gridlines"><i></i><i></i><i></i><i></i></div>
                    <div class="crm-dashboard-chart__months">
                        @foreach ($this->commercialEvolution as $month)
                            <div class="crm-dashboard-chart__month" wire:key="dashboard-month-{{ $month['label'] }}">
                                <div class="crm-dashboard-chart__bars">
                                    <i class="is-leads" style="height: {{ max(4, (int) round(($month['leads'] / $evolutionMax) * 100)) }}%" title="{{ $month['leads'] }} leads"></i>
                                    <i class="is-reservations" style="height: {{ max(4, (int) round(($month['reservations'] / $evolutionMax) * 100)) }}%" title="{{ $month['reservations'] }} reservas"></i>
                                    <i class="is-sales" style="height: {{ max(4, (int) round(($month['sales'] / $evolutionMax) * 100)) }}%" title="{{ $month['sales'] }} ventas"></i>
                                </div>
                                <span>{{ $month['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="crm-dashboard-legend">
                <span><i class="is-leads"></i>Leads</span>
                <span><i class="is-reservations"></i>Reservas</span>
                <span><i class="is-sales"></i>Ventas</span>
            </div>
        </section>

        <section class="crm-card crm-dashboard-funnel-card">
            <div class="crm-dashboard-section-heading">
                <div>
                    <p class="crm-eyebrow">Embudo de leads</p>
                    <h2 class="crm-section-title">Conversión por etapa</h2>
                    <p class="crm-module-description">Distribución actual de los leads visibles.</p>
                </div>
                <a class="crm-dashboard-link" href="{{ route('pipeline.index') }}" wire:navigate>Ver pipeline <i class="bi bi-arrow-up-right"></i></a>
            </div>
            <div class="crm-dashboard-funnel">
                @forelse ($this->pipeline as $stage)
                    <div class="crm-dashboard-funnel__row" wire:key="dashboard-stage-{{ $stage['name'] }}">
                        <span>{{ $stage['name'] }}</span>
                        <div class="crm-dashboard-funnel__track"><i style="width: {{ max(2, $stage['percentage']) }}%; background: {{ $stage['color'] }}"></i></div>
                        <strong>{{ $stage['count'] }}</strong>
                        <small>{{ $stage['percentage'] }}%</small>
                    </div>
                @empty
                    <div class="crm-empty">No hay etapas configuradas.</div>
                @endforelse
            </div>
        </section>
    </div>

    <section class="crm-card crm-dashboard-access-card">
        <div class="crm-dashboard-section-heading">
            <div>
                <p class="crm-eyebrow">Accesos por rol</p>
                <h2 class="crm-section-title">Permisos disponibles en el sistema</h2>
            </div>
            <p class="crm-module-description">Cada rol muestra únicamente el alcance permitido para el usuario actual.</p>
        </div>
        <div class="crm-dashboard-role-grid">
            @foreach ($this->roleCards as $role)
                <article class="crm-dashboard-role-card {{ $role['is_current'] ? 'is-current' : '' }}" wire:key="dashboard-access-{{ $role['slug'] }}">
                    <div class="crm-dashboard-role-card__icon"><i class="bi {{ $role['icon'] }}" aria-hidden="true"></i></div>
                    <div class="crm-dashboard-role-card__body">
                        <div class="crm-dashboard-role-card__heading">
                            <h3>{{ $role['name'] }}</h3>
                            @if ($role['is_current'])
                                <span>Rol activo</span>
                            @endif
                        </div>
                        <p>{{ $role['description'] }}</p>
                        <ul>
                            @forelse ($role['permissions'] as $permission)
                                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i>{{ $permission }}</li>
                            @empty
                                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Sin permisos configurados</li>
                            @endforelse
                        </ul>
                        <small>{{ $role['permission_count'] }} permisos registrados</small>
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <div class="crm-dashboard-lower-grid">
        <section class="crm-card crm-dashboard-list-card">
            <div class="crm-dashboard-section-heading">
                <div>
                    <p class="crm-eyebrow">Últimos leads</p>
                    <h2 class="crm-section-title">Oportunidades recientes</h2>
                </div>
                <a class="crm-dashboard-link" href="{{ route('leads.index') }}" wire:navigate>Ver todos <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="crm-dashboard-list">
                @forelse ($this->recentLeads as $lead)
                    <a class="crm-dashboard-list-row" href="{{ route('leads.show', $lead) }}" wire:navigate wire:key="dashboard-lead-{{ $lead->id }}">
                        <span class="crm-dashboard-avatar">{{ Str::initials($lead->full_name, true) }}</span>
                        <span class="crm-dashboard-list-row__content"><strong>{{ $lead->full_name }}</strong><small>{{ $lead->source?->name ?? 'Sin origen' }} · {{ $lead->property_type ?? 'Interés no definido' }}</small></span>
                        <span class="crm-badge crm-badge--info">{{ $lead->pipelineStatus?->name ?? 'Nuevo' }}</span>
                    </a>
                @empty
                    <div class="crm-empty">Todavía no hay leads registrados.</div>
                @endforelse
            </div>
        </section>

        <section class="crm-card crm-dashboard-list-card">
            <div class="crm-dashboard-section-heading">
                <div>
                    <p class="crm-eyebrow">Seguimientos de hoy</p>
                    <h2 class="crm-section-title">Agenda del equipo</h2>
                </div>
                <a class="crm-dashboard-link" href="{{ route('follow-ups.index') }}" wire:navigate>Ver todos <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="crm-dashboard-list">
                @forelse ($this->todayFollowUps as $followUp)
                    <article class="crm-dashboard-list-row" wire:key="dashboard-follow-up-{{ $followUp->id }}">
                        <span class="crm-dashboard-time">{{ $followUp->scheduled_at->format('H:i') }}</span>
                        <span class="crm-dashboard-list-row__content"><strong>{{ $followUp->lead?->full_name ?? 'Lead eliminado' }}</strong><small>{{ $followUp->type }} · {{ $followUp->assignedUser?->name ?? 'Sin asignar' }}</small></span>
                        <span class="crm-badge crm-badge--warning">{{ $followUp->priorityLabel() }}</span>
                    </article>
                @empty
                    <div class="crm-empty">Sin seguimientos programados para hoy.</div>
                @endforelse
            </div>
        </section>

        <section class="crm-card crm-dashboard-list-card">
            <div class="crm-dashboard-section-heading">
                <div>
                    <p class="crm-eyebrow">Actividad reciente</p>
                    <h2 class="crm-section-title">Últimos movimientos</h2>
                </div>
                <a class="crm-dashboard-link" href="{{ route('reports.index') }}" wire:navigate>Ver reportes <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="crm-dashboard-list">
                @forelse ($this->recentActivities as $activity)
                    <article class="crm-dashboard-activity-row" wire:key="dashboard-activity-{{ $activity->id }}">
                        <span class="crm-dashboard-activity-icon"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i></span>
                        <span><strong>{{ $activity->title }}</strong><small>{{ $activity->lead?->full_name ?? 'Lead' }} · {{ $activity->user?->name ?? 'Sistema' }}</small></span>
                        <time datetime="{{ $activity->created_at?->toIso8601String() }}">{{ $activity->created_at?->diffForHumans() }}</time>
                    </article>
                @empty
                    <div class="crm-empty">No hay actividad registrada.</div>
                @endforelse
            </div>
        </section>

        <section class="crm-card crm-dashboard-list-card crm-dashboard-alerts-card">
            <div class="crm-dashboard-section-heading">
                <div>
                    <p class="crm-eyebrow">Alertas administrativas</p>
                    <h2 class="crm-section-title">Requieren atención</h2>
                </div>
                <a class="crm-dashboard-link" href="{{ route('notifications.index') }}" wire:navigate>Ver todas <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="crm-dashboard-alert-list">
                @foreach ($this->alerts as $alert)
                    <a class="crm-dashboard-alert crm-dashboard-alert--{{ $alert['tone'] }}" href="{{ $alert['href'] }}" wire:navigate wire:key="dashboard-alert-{{ $alert['label'] }}">
                        <span class="crm-dashboard-alert__icon"><i class="bi {{ $alert['icon'] }}" aria-hidden="true"></i></span>
                        <span><strong>{{ $alert['value'] }} {{ $alert['label'] }}</strong><small>{{ $alert['description'] }}</small></span>
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                @endforeach
            </div>
        </section>
    </div>
</section>
