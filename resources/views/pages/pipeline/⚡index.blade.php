<?php

use App\Actions\Leads\UpdateLeadStageAction;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Enums\RecordStatus;
use App\Enums\ReservationStatus;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Reservation;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Pipeline')] class extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $assignedUser = '';

    #[Url]
    public string $source = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    public function mount(): void
    {
        Gate::authorize('pipeline.view');
        Auth::user()->loadMissing('role');
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'assignedUser', 'source', 'dateFrom', 'dateTo'], true)) {
            $this->resetComputedState();
        }
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->assignedUser = '';
        $this->source = '';
        $this->dateFrom = '';
        $this->dateTo = '';
    }

    public function moveLead(int $leadId, int $statusId, UpdateLeadStageAction $updateLeadStage): void
    {
        $lead = Lead::query()->findOrFail($leadId);
        LeadStatus::query()->where('status', RecordStatus::Active->value)->findOrFail($statusId);
        Gate::authorize('update', $lead);
        Gate::authorize('pipeline.edit');

        $updateLeadStage->handle($lead, $statusId, Auth::user());
        Flux::toast(variant: 'success', text: 'Etapa actualizada.');
    }

    public function exportPipeline(): StreamedResponse
    {
        Gate::authorize('pipeline.view');

        $leads = $this->filteredLeadsQuery()
            ->with(['pipelineStatus', 'assignedUser', 'source'])
            ->latest('entered_at')
            ->limit(5000)
            ->get();

        return response()->streamDownload(function () use ($leads): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Lead', 'Etapa', 'Origen', 'Distrito', 'Presupuesto', 'Asesor', 'Proximo seguimiento', 'Ingreso'], ';');

            foreach ($leads as $lead) {
                fputcsv($handle, [
                    $lead->full_name,
                    $lead->pipelineStatus?->name,
                    $lead->source?->name,
                    $lead->district,
                    $lead->budgetDisplay(),
                    $lead->assignedUser?->name ?? 'Sin asesor',
                    $lead->next_follow_up_at?->format('d/m/Y H:i'),
                    $lead->entered_at?->format('d/m/Y H:i'),
                ], ';');
            }

            fclose($handle);
        }, 'pipeline-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    #[Computed]
    public function currentRoleLabel(): string
    {
        return Auth::user()->role?->name ?? 'Asesor';
    }

    #[Computed]
    public function statuses(): \Illuminate\Support\Collection
    {
        return LeadStatus::query()
            ->where('status', RecordStatus::Active->value)
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function sources(): \Illuminate\Support\Collection
    {
        return LeadSource::query()
            ->where('status', RecordStatus::Active->value)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function advisors(): \Illuminate\Support\Collection
    {
        $user = Auth::user();

        return User::query()
            ->where('status', RecordStatus::Active->value)
            ->when($user->isSupervisor(), fn (Builder $query) => $query->where(function (Builder $teamQuery) use ($user): void {
                $teamQuery->whereKey($user->id)->orWhere('supervisor_user_id', $user->id);
            }))
            ->when($user->isAsesor(), fn (Builder $query) => $query->whereKey($user->id))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function leads(): \Illuminate\Support\Collection
    {
        return $this->filteredLeadsQuery()
            ->with(['pipelineStatus', 'assignedUser', 'source'])
            ->latest('entered_at')
            ->limit(300)
            ->get()
            ->groupBy('status_id');
    }

    #[Computed]
    public function pipelineStats(): array
    {
        $user = Auth::user();
        $leadQuery = $this->visibleLeadsQuery();
        $currentStart = now()->startOfMonth();
        $currentEnd = now()->endOfMonth();
        $previousStart = now()->subMonthNoOverflow()->startOfMonth();
        $previousEnd = now()->subMonthNoOverflow()->endOfMonth();

        $currentLeads = fn (): int => (clone $leadQuery)->whereBetween('entered_at', [$currentStart, $currentEnd])->count();
        $previousLeads = fn (): int => (clone $leadQuery)->whereBetween('entered_at', [$previousStart, $previousEnd])->count();
        $currentVisits = fn (): int => FollowUp::query()->visibleFor($user)->where('status', FollowUpStatus::Pending->value)->where('type', FollowUpType::Visit->value)->whereBetween('scheduled_at', [$currentStart, $currentEnd])->count();
        $previousVisits = fn (): int => FollowUp::query()->visibleFor($user)->where('status', FollowUpStatus::Pending->value)->where('type', FollowUpType::Visit->value)->whereBetween('scheduled_at', [$previousStart, $previousEnd])->count();
        $currentProposals = fn (): int => (clone $leadQuery)->whereHas('pipelineStatus', fn (Builder $query) => $query->where('slug', 'negotiation'))->whereBetween('entered_at', [$currentStart, $currentEnd])->count();
        $previousProposals = fn (): int => (clone $leadQuery)->whereHas('pipelineStatus', fn (Builder $query) => $query->where('slug', 'negotiation'))->whereBetween('entered_at', [$previousStart, $previousEnd])->count();
        $currentReservations = fn (): int => Reservation::query()->visibleFor($user)->whereIn('status', [ReservationStatus::Active->value, ReservationStatus::Completed->value])->whereBetween('reserved_at', [$currentStart, $currentEnd])->count();
        $previousReservations = fn (): int => Reservation::query()->visibleFor($user)->whereIn('status', [ReservationStatus::Active->value, ReservationStatus::Completed->value])->whereBetween('reserved_at', [$previousStart, $previousEnd])->count();

        $total = (clone $leadQuery)->count();
        $won = (clone $leadQuery)->whereHas('pipelineStatus', fn (Builder $query) => $query->where('slug', 'closed_won'))->count();

        return [
            ['key' => 'leads', 'label' => 'Leads activos', 'value' => number_format($total), 'change' => $this->percentageChange($currentLeads(), $previousLeads()), 'icon' => 'bi-people', 'tone' => 'blue'],
            ['key' => 'visits', 'label' => 'Visitas agendadas', 'value' => number_format(FollowUp::query()->visibleFor($user)->where('status', FollowUpStatus::Pending->value)->where('type', FollowUpType::Visit->value)->where('scheduled_at', '>=', now())->count()), 'change' => $this->percentageChange($currentVisits(), $previousVisits()), 'icon' => 'bi-calendar-check', 'tone' => 'cyan'],
            ['key' => 'proposals', 'label' => 'Propuestas', 'value' => number_format((clone $leadQuery)->whereHas('pipelineStatus', fn (Builder $query) => $query->where('slug', 'negotiation'))->count()), 'change' => $this->percentageChange($currentProposals(), $previousProposals()), 'icon' => 'bi-file-earmark-text', 'tone' => 'amber'],
            ['key' => 'reservations', 'label' => 'Reservas', 'value' => number_format(Reservation::query()->visibleFor($user)->where('status', ReservationStatus::Active->value)->count()), 'change' => $this->percentageChange($currentReservations(), $previousReservations()), 'icon' => 'bi-handshake', 'tone' => 'purple'],
            ['key' => 'conversion', 'label' => 'Conversión', 'value' => $total > 0 ? number_format(($won / $total) * 100, 1).'%' : '0.0%', 'change' => $this->percentageChange($won, $total), 'icon' => 'bi-bar-chart', 'tone' => 'indigo'],
        ];
    }

    #[Computed]
    public function recentActivities(): \Illuminate\Support\Collection
    {
        return LeadActivity::query()
            ->whereHas('lead', fn (Builder $query) => $query->visibleFor(Auth::user()))
            ->with(['lead:id,full_name', 'user:id,name'])
            ->latest()
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function alerts(): array
    {
        $query = $this->visibleLeadsQuery();
        $pendingFollowUps = FollowUp::query()
            ->visibleFor(Auth::user())
            ->where('status', FollowUpStatus::Pending->value)
            ->whereHas('lead', fn (Builder $leadQuery) => $leadQuery->where('status', RecordStatus::Active->value));

        return [
            ['label' => 'Leads con seguimiento vencido', 'description' => 'Sin seguimiento en los últimos 3 días', 'value' => (clone $pendingFollowUps)->where('scheduled_at', '<', now())->count(), 'icon' => 'bi-stopwatch', 'tone' => 'danger'],
            ['label' => 'Visitas próximas', 'description' => 'Visitas agendadas para hoy o mañana', 'value' => (clone $pendingFollowUps)->where('type', FollowUpType::Visit->value)->whereBetween('scheduled_at', [now()->startOfDay(), now()->addDay()->endOfDay()])->count(), 'icon' => 'bi-calendar-event', 'tone' => 'amber'],
            ['label' => 'Leads estancados', 'description' => 'Sin contacto por más de 7 días', 'value' => (clone $query)->where(function (Builder $leadQuery): void { $leadQuery->whereNull('last_contact_at')->orWhere('last_contact_at', '<', now()->subDays(7)); })->count(), 'icon' => 'bi-exclamation-triangle', 'tone' => 'danger'],
            ['label' => 'Leads sin asesor asignado', 'description' => 'Disponibles para asignación', 'value' => (clone $query)->whereNull('assigned_user_id')->count(), 'icon' => 'bi-people', 'tone' => 'blue'],
        ];
    }

    public function stageLeads(int $statusId): \Illuminate\Support\Collection
    {
        return $this->leads->get($statusId, collect());
    }

    public function stageTotal(int $statusId): string
    {
        $amount = $this->stageLeads($statusId)->sum(fn (Lead $lead): float => (float) ($lead->budget_max ?? $lead->budget_min ?? 0));

        return 'S/ '.number_format($amount, 0);
    }

    /** @return array{label: string, class: string} */
    public function priorityFor(Lead $lead): array
    {
        if ($lead->next_follow_up_at?->isPast()) {
            return ['label' => 'Alta', 'class' => 'is-high'];
        }

        if ($lead->next_follow_up_at !== null && $lead->next_follow_up_at->diffInDays(now()) <= 2) {
            return ['label' => 'Media', 'class' => 'is-medium'];
        }

        return ['label' => 'Baja', 'class' => 'is-low'];
    }

    public function followUpLabel(?Carbon $date): string
    {
        if ($date === null) {
            return 'Sin seguimiento';
        }

        if ($date->isToday()) {
            return 'Hoy '.$date->format('H:i');
        }

        if ($date->isTomorrow()) {
            return 'Mañana '.$date->format('H:i');
        }

        return $date->format('d M H:i');
    }

    public function avatarTone(int $id): string
    {
        return ['blue', 'purple', 'pink', 'green', 'orange'][$id % 5];
    }

    public function activityIcon(?string $type): string
    {
        return match ($type) {
            'CALL' => 'bi-telephone', 'WHATSAPP' => 'bi-whatsapp', 'EMAIL' => 'bi-envelope', 'VISIT' => 'bi-calendar-check', default => 'bi-plus-lg',
        };
    }

    private function visibleLeadsQuery(): Builder
    {
        return Lead::query()->visibleFor(Auth::user())->where('status', RecordStatus::Active->value);
    }

    private function filteredLeadsQuery(): Builder
    {
        return $this->visibleLeadsQuery()
            ->when($this->search !== '', function (Builder $query): void {
                $search = trim($this->search);
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('full_name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')->orWhere('district', 'like', '%'.$search.'%');
                });
            })
            ->when($this->assignedUser !== '', fn (Builder $query) => $query->where('assigned_user_id', (int) $this->assignedUser))
            ->when($this->source !== '', fn (Builder $query) => $query->where('source_id', (int) $this->source))
            ->when($this->dateValue($this->dateFrom) !== null, fn (Builder $query) => $query->whereDate('entered_at', '>=', $this->dateValue($this->dateFrom)))
            ->when($this->dateValue($this->dateTo) !== null, fn (Builder $query) => $query->whereDate('entered_at', '<=', $this->dateValue($this->dateTo)));
    }

    private function dateValue(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function percentageChange(int|float $current, int|float $previous): string
    {
        if ($previous == 0) {
            return $current > 0 ? '+100%' : '+0%';
        }

        $change = (($current - $previous) / $previous) * 100;

        return ($change >= 0 ? '+' : '').number_format($change, 1).'%';
    }

    private function resetComputedState(): void
    {
        unset($this->leads, $this->pipelineStats, $this->recentActivities, $this->alerts);
    }
};
?>

<section class="crm-page crm-pipeline-page">
    <header class="crm-page-header crm-pipeline-page-header">
        <div>
            <p class="crm-eyebrow">Comercial</p>
            <h1 class="crm-module-title">Pipeline</h1>
            <p class="crm-module-description">Gestión visual y administrativa de oportunidades por etapa del proceso comercial.</p>
            <p class="crm-pipeline-scope"><i class="bi bi-shield-check" aria-hidden="true"></i> {{ $this->currentRoleLabel }} · Vista aislada por rol y equipo</p>
        </div>
        <div class="crm-actions crm-pipeline-header-actions">
            <flux:button icon="arrow-path" wire:click="$refresh" wire:loading.attr="disabled">Actualizar</flux:button>
            <flux:button icon="arrow-down-tray" wire:click="exportPipeline" wire:loading.attr="disabled" wire:target="exportPipeline">Exportar</flux:button>
            @can('leads.create')
                <flux:button icon="plus" variant="primary" :href="route('leads.index', ['showForm' => 1])" wire:navigate>Nuevo lead</flux:button>
            @endcan
        </div>
    </header>

    <section class="crm-pipeline-stats" aria-label="Resumen del pipeline">
        @foreach ($this->pipelineStats as $stat)
            <article class="crm-pipeline-stat" wire:key="pipeline-stat-{{ $stat['key'] }}">
                <span class="crm-pipeline-stat-icon is-{{ $stat['tone'] }}"><i class="bi {{ $stat['icon'] }}" aria-hidden="true"></i></span>
                <span>{{ $stat['label'] }}</span>
                <strong>{{ $stat['value'] }}</strong>
                <small><i class="bi bi-arrow-up-right" aria-hidden="true"></i> {{ $stat['change'] }} <em>vs. mes anterior</em></small>
            </article>
        @endforeach
    </section>

    <section class="crm-card crm-pipeline-filters">
        <div class="crm-pipeline-filter-top">
            <flux:input wire:model.live.debounce.350ms="search" icon="magnifying-glass" placeholder="Buscar lead por nombre, teléfono o propiedad..." />
            <div class="crm-actions">
                <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="resetFilters">Limpiar filtros</flux:button>
            </div>
        </div>
        <div class="crm-pipeline-filter-grid">
            <flux:select wire:model.live="assignedUser" label="Asesor"><option value="">Todos</option>@foreach ($this->advisors as $advisor)<option value="{{ $advisor->id }}" wire:key="pipeline-advisor-{{ $advisor->id }}">{{ $advisor->name }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="source" label="Origen"><option value="">Todos</option>@foreach ($this->sources as $item)<option value="{{ $item->id }}" wire:key="pipeline-source-{{ $item->id }}">{{ $item->name }}</option>@endforeach</flux:select>
            <div class="crm-pipeline-date-range"><flux:input wire:model.live="dateFrom" label="Desde" type="date" /><span>—</span><flux:input wire:model.live="dateTo" label="Hasta" type="date" /></div>
            <div class="crm-pipeline-view-indicator"><span>Vista</span><strong><i class="bi bi-kanban" aria-hidden="true"></i> Kanban</strong></div>
        </div>
    </section>

    <div class="crm-pipeline-board">
        @foreach ($this->statuses as $status)
            @php($stageLeads = $this->stageLeads($status->id))
            <section class="crm-pipeline-column" style="--stage-color: {{ $status->color ?: '#008cff' }}" wire:key="pipeline-stage-{{ $status->id }}">
                <header class="crm-pipeline-column-header">
                    <div class="crm-pipeline-column-title"><span></span><div><h2>{{ $status->name }}</h2><p>{{ $stageLeads->count() }} lead(s) <b>·</b> {{ $this->stageTotal($status->id) }}</p></div></div>
                    @can('pipeline.edit')<button type="button" class="crm-pipeline-column-more" title="Opciones de etapa" aria-label="Opciones de etapa"><i class="bi bi-three-dots-vertical" aria-hidden="true"></i></button>@endcan
                </header>
                <div class="crm-pipeline-column-body">
                    @forelse ($stageLeads as $lead)
                        @php($priority = $this->priorityFor($lead))
                        <article class="crm-pipeline-card" wire:key="pipeline-lead-{{ $lead->id }}">
                            <div class="crm-pipeline-card-top">
                                <a href="{{ route('leads.show', $lead) }}" wire:navigate class="crm-pipeline-person">
                                    <span class="crm-pipeline-avatar is-{{ $this->avatarTone($lead->id) }}">{{ Str::initials($lead->full_name, true) }}</span>
                                    <span><strong>{{ $lead->full_name }}</strong><small>{{ $lead->phone }}</small></span>
                                </a>
                                <span class="crm-pipeline-priority {{ $priority['class'] }}">{{ $priority['label'] }}</span>
                            </div>
                            <p class="crm-pipeline-location"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ $lead->property_type ?: 'Interés inmobiliario' }} · {{ $lead->district ?: 'Sin distrito' }}</p>
                            <strong class="crm-pipeline-budget">{{ $lead->budgetDisplay() }}</strong>
                            <div class="crm-pipeline-card-meta"><span><i class="bi bi-circle-fill" aria-hidden="true"></i> {{ $lead->source?->name ?? 'Sin origen' }}</span><time><i class="bi bi-calendar3" aria-hidden="true"></i> {{ $this->followUpLabel($lead->next_follow_up_at) }}</time></div>
                            <footer class="crm-pipeline-card-footer">
                                <span class="crm-pipeline-assignee"><i class="bi bi-person-circle" aria-hidden="true"></i>{{ $lead->assignedUser?->name ?? 'Sin asesor' }}</span>
                                <div class="crm-pipeline-card-actions">
                                    @if ($lead->phone)<a href="tel:{{ $lead->phone }}" title="Llamar" aria-label="Llamar a {{ $lead->full_name }}"><i class="bi bi-telephone" aria-hidden="true"></i></a>@endif
                                    @if ($lead->email)<a href="mailto:{{ $lead->email }}" title="Enviar correo" aria-label="Enviar correo a {{ $lead->full_name }}"><i class="bi bi-envelope" aria-hidden="true"></i></a>@endif
                                    @can('update', $lead)
                                        @can('pipeline.edit')
                                            <details class="crm-pipeline-move-menu">
                                                <summary title="Mover de etapa" aria-label="Mover {{ $lead->full_name }} de etapa"><i class="bi bi-three-dots" aria-hidden="true"></i></summary>
                                                <div>
                                                    <strong>Mover a</strong>
                                                    @foreach ($this->statuses as $target)
                                                        @if ($target->id !== $status->id)
                                                            <button type="button" wire:click="moveLead({{ $lead->id }}, {{ $target->id }})" wire:key="move-{{ $lead->id }}-{{ $target->id }}">{{ $target->name }}</button>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            </details>
                                        @endcan
                                    @endcan
                                </div>
                            </footer>
                        </article>
                    @empty
                        <div class="crm-pipeline-empty"><i class="bi bi-inbox" aria-hidden="true"></i><span>Sin leads en esta etapa</span></div>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>

    <div class="crm-pipeline-bottom-grid">
        <section class="crm-card crm-pipeline-list-card">
            <div class="crm-pipeline-card-heading"><div><h2><i class="bi bi-clock-history" aria-hidden="true"></i> Actividad reciente</h2><p>Últimos movimientos registrados en el pipeline.</p></div><flux:button size="sm" variant="ghost" :href="route('follow-ups.index')" wire:navigate>Ver todas <i class="bi bi-arrow-right" aria-hidden="true"></i></flux:button></div>
            <div class="crm-pipeline-activity-list">
                @forelse ($this->recentActivities as $activity)
                    <article class="crm-pipeline-activity" wire:key="pipeline-activity-{{ $activity->id }}"><span class="crm-pipeline-activity-icon is-{{ $this->avatarTone($activity->id) }}"><i class="bi {{ $this->activityIcon($activity->type) }}" aria-hidden="true"></i></span><div><strong>{{ $activity->user?->name ?? 'Sistema' }}</strong><span>{{ $activity->title }}</span><small>{{ $activity->lead?->full_name }} · {{ $activity->created_at?->diffForHumans() }}</small></div></article>
                @empty
                    <div class="crm-pipeline-mini-empty">No hay actividad reciente para mostrar.</div>
                @endforelse
            </div>
        </section>
        <section class="crm-card crm-pipeline-list-card">
            <div class="crm-pipeline-card-heading"><div><h2><i class="bi bi-bell" aria-hidden="true"></i> Alertas del pipeline</h2><p>Prioridades que requieren seguimiento.</p></div><flux:button size="sm" variant="ghost" :href="route('follow-ups.index')" wire:navigate>Ver todas <i class="bi bi-arrow-right" aria-hidden="true"></i></flux:button></div>
            <div class="crm-pipeline-alert-list">
                @foreach ($this->alerts as $alert)
                    <article class="crm-pipeline-alert" wire:key="pipeline-alert-{{ $alert['label'] }}"><span class="crm-pipeline-alert-icon is-{{ $alert['tone'] }}"><i class="bi {{ $alert['icon'] }}" aria-hidden="true"></i></span><div><strong>{{ number_format($alert['value']) }} {{ $alert['label'] }}</strong><small>{{ $alert['description'] }}</small></div><span class="crm-pipeline-alert-count is-{{ $alert['tone'] }}">{{ number_format($alert['value']) }}</span></article>
                @endforeach
            </div>
        </section>
    </div>
</section>
