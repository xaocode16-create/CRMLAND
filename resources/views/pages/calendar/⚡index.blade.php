<?php

use App\Enums\FollowUpPriority;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Enums\RecordStatus;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Calendario')] class extends Component
{
    private const START_HOUR = 8;

    private const END_HOUR = 19;

    #[Url]
    public string $viewMode = 'week';

    #[Url]
    public string $cursorDate = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $assignedUser = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('appointments.view');
        Auth::user()->loadMissing('role');

        $this->cursorDate = $this->validDate($this->cursorDate) ?? now()->toDateString();
        $this->syncDateRange();
        $this->resetForm();
    }

    public function setViewMode(string $mode): void
    {
        if (! in_array($mode, ['day', 'week', 'month'], true)) {
            return;
        }

        $this->viewMode = $mode;
        $this->syncDateRange();
    }

    public function today(): void
    {
        $this->cursorDate = now()->toDateString();
        $this->syncDateRange();
    }

    public function previousPeriod(): void
    {
        $date = $this->cursorCarbon();
        $this->cursorDate = match ($this->viewMode) {
            'day' => $date->subDay()->toDateString(),
            'month' => $date->subMonthNoOverflow()->toDateString(),
            default => $date->subWeek()->toDateString(),
        };
        $this->syncDateRange();
    }

    public function nextPeriod(): void
    {
        $date = $this->cursorCarbon();
        $this->cursorDate = match ($this->viewMode) {
            'day' => $date->addDay()->toDateString(),
            'month' => $date->addMonthNoOverflow()->toDateString(),
            default => $date->addWeek()->toDateString(),
        };
        $this->syncDateRange();
    }

    public function previousMonth(): void
    {
        $this->cursorDate = $this->cursorCarbon()->subMonthNoOverflow()->toDateString();
        $this->syncDateRange();
    }

    public function nextMonth(): void
    {
        $this->cursorDate = $this->cursorCarbon()->addMonthNoOverflow()->toDateString();
        $this->syncDateRange();
    }

    public function chooseDate(string $date): void
    {
        $validDate = $this->validDate($date);

        if ($validDate === null) {
            return;
        }

        $this->cursorDate = $validDate;
        $this->syncDateRange();
    }

    public function resetFilters(): void
    {
        $this->status = '';
        $this->assignedUser = '';
        $this->type = '';
        $this->search = '';
        $this->today();
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'lead_id' => null,
            'assigned_user_id' => Auth::id(),
            'type' => FollowUpType::Call->value,
            'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'priority' => FollowUpPriority::Normal->value,
            'note' => '',
        ];
    }

    public function create(): void
    {
        Gate::authorize('create', FollowUp::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $followUpId): void
    {
        $followUp = FollowUp::query()->findOrFail($followUpId);
        Gate::authorize('update', $followUp);

        $this->editingId = $followUp->id;
        $this->form = [
            'lead_id' => $followUp->lead_id,
            'assigned_user_id' => $followUp->assigned_user_id,
            'type' => $followUp->type,
            'scheduled_at' => $followUp->scheduled_at?->format('Y-m-d\TH:i'),
            'priority' => $followUp->priority,
            'note' => $followUp->note,
        ];
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function save(): void
    {
        $followUp = $this->editingId ? FollowUp::query()->findOrFail($this->editingId) : null;
        $followUp ? Gate::authorize('update', $followUp) : Gate::authorize('create', FollowUp::class);

        $validated = $this->validate([
            'form.lead_id' => ['required', 'integer', 'exists:leads,id'],
            'form.assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'form.type' => ['required', Rule::in(array_column(FollowUpType::cases(), 'value'))],
            'form.scheduled_at' => ['required', 'date'],
            'form.priority' => ['required', Rule::in(array_column(FollowUpPriority::cases(), 'value'))],
            'form.note' => ['nullable', 'string', 'max:1000'],
        ])['form'];

        $lead = Lead::query()->findOrFail((int) $validated['lead_id']);
        Gate::authorize('view', $lead);

        $assignedUserId = $validated['assigned_user_id'] !== null
            ? (int) $validated['assigned_user_id']
            : (int) ($lead->assigned_user_id ?: Auth::id());

        abort_unless(Auth::user()->canAssignTo($assignedUserId), 403);

        $data = [
            'lead_id' => $lead->id,
            'assigned_user_id' => $assignedUserId,
            'type' => $validated['type'],
            'scheduled_at' => $validated['scheduled_at'],
            'priority' => $validated['priority'],
            'note' => $validated['note'] ?: null,
        ];

        if ($followUp instanceof FollowUp) {
            $followUp->update($data);
            Flux::toast(variant: 'success', text: 'Evento actualizado.');
        } else {
            FollowUp::query()->create($data);
            Flux::toast(variant: 'success', text: 'Evento creado en el calendario.');
        }

        $lead->update(['next_follow_up_at' => $validated['scheduled_at']]);
        $this->closeForm();
    }

    public function complete(int $followUpId): void
    {
        $followUp = FollowUp::query()->findOrFail($followUpId);
        Gate::authorize('update', $followUp);

        $followUp->update([
            'status' => FollowUpStatus::Completed->value,
            'completed_at' => now(),
            'completed_by_user_id' => Auth::id(),
        ]);

        $followUp->lead()->update(['last_contact_at' => now()]);
        Flux::toast(variant: 'success', text: 'Actividad completada.');
    }

    public function cancel(int $followUpId): void
    {
        $followUp = FollowUp::query()->findOrFail($followUpId);
        Gate::authorize('update', $followUp);
        $followUp->update(['status' => FollowUpStatus::Cancelled->value]);
        Flux::toast(variant: 'success', text: 'Actividad cancelada.');
    }

    public function exportCalendar(): StreamedResponse
    {
        Gate::authorize('appointments.view');

        $events = $this->filteredEventsQuery()
            ->with(['lead', 'assignedUser'])
            ->orderBy('scheduled_at')
            ->limit(5000)
            ->get();

        return response()->streamDownload(function () use ($events): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Fecha', 'Hora', 'Tipo', 'Lead', 'Telefono', 'Asesor', 'Estado', 'Prioridad', 'Nota'], ';');

            foreach ($events as $event) {
                fputcsv($handle, [
                    $event->scheduled_at?->format('d/m/Y'),
                    $event->scheduled_at?->format('H:i'),
                    $event->typeLabel(),
                    $event->lead?->full_name,
                    $event->lead?->phone,
                    $event->assignedUser?->name ?? 'Sin asesor',
                    $this->displayStatus($event),
                    $event->priorityLabel(),
                    $event->note,
                ], ';');
            }

            fclose($handle);
        }, 'calendario-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    #[Computed]
    public function currentRoleLabel(): string
    {
        return Auth::user()->role?->name ?? 'Asesor';
    }

    #[Computed]
    public function rangeLabel(): string
    {
        $from = $this->rangeStart();
        $to = $this->rangeEnd();

        return match ($this->viewMode) {
            'day' => $from->locale('es')->translatedFormat('l, d \d\e F \d\e Y'),
            'month' => $from->locale('es')->translatedFormat('F \d\e Y'),
            default => $from->format('d').' - '.$to->format('d').' '.$to->locale('es')->translatedFormat('F \d\e Y'),
        };
    }

    #[Computed]
    public function metrics(): array
    {
        $query = $this->baseFollowUpsQuery();
        $today = now();
        $periodStart = $this->rangeStart()->startOfDay();
        $periodEnd = $this->rangeEnd()->endOfDay();
        $periodQuery = (clone $query)->whereBetween('scheduled_at', [$periodStart, $periodEnd]);
        $closed = (clone $periodQuery)->where('status', FollowUpStatus::Completed->value)->count();
        $pending = (clone $periodQuery)->where('status', FollowUpStatus::Pending->value)->count();

        return [
            ['label' => 'Actividades hoy', 'value' => (clone $query)->whereDate('scheduled_at', $today)->count(), 'detail' => 'vs. ayer', 'icon' => 'bi-calendar-check', 'tone' => 'blue'],
            ['label' => 'Visitas programadas', 'value' => (clone $query)->where('type', FollowUpType::Visit->value)->where('status', FollowUpStatus::Pending->value)->where('scheduled_at', '>=', $today)->count(), 'detail' => 'próximas', 'icon' => 'bi-house-check', 'tone' => 'cyan'],
            ['label' => 'Seguimientos pendientes', 'value' => $pending, 'detail' => 'requieren atención', 'icon' => 'bi-clipboard-check', 'tone' => 'red'],
            ['label' => 'Reuniones', 'value' => (clone $periodQuery)->where('type', FollowUpType::Meeting->value)->count(), 'detail' => 'en el periodo', 'icon' => 'bi-people', 'tone' => 'violet'],
            ['label' => 'Cumplimiento', 'value' => ($closed + $pending) > 0 ? (int) round(($closed / ($closed + $pending)) * 100).'%' : '0%', 'detail' => 'eventos cerrados', 'icon' => 'bi-graph-up-arrow', 'tone' => 'green'],
        ];
    }

    #[Computed]
    public function weekDays(): Collection
    {
        $start = $this->cursorCarbon()->startOfWeek();

        return collect(range(0, 6))->map(function (int $offset) use ($start): array {
            $date = $start->copy()->addDays($offset);

            return [
                'date' => $date->toDateString(),
                'short' => ucfirst($date->locale('es')->isoFormat('ddd')),
                'day' => $date->format('d'),
                'month' => $date->locale('es')->isoFormat('MMM'),
                'isToday' => $date->isToday(),
            ];
        });
    }

    #[Computed]
    public function hours(): array
    {
        return range(self::START_HOUR, self::END_HOUR);
    }

    #[Computed]
    public function calendarEvents(): Collection
    {
        return $this->filteredEventsQuery()
            ->with(['lead:id,full_name,phone', 'assignedUser:id,name'])
            ->orderBy('scheduled_at')
            ->get();
    }

    #[Computed]
    public function eventsByDay(): array
    {
        return $this->calendarEvents->groupBy(fn (FollowUp $event): string => $event->scheduled_at->toDateString())->all();
    }

    #[Computed]
    public function monthDays(): Collection
    {
        $cursor = $this->cursorCarbon();
        $start = $cursor->copy()->startOfMonth()->startOfWeek();
        $end = $cursor->copy()->endOfMonth()->endOfWeek();
        $days = [];

        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $days[] = [
                'date' => $date->toDateString(),
                'day' => $date->format('j'),
                'isCurrentMonth' => $date->month === $cursor->month,
                'isToday' => $date->isToday(),
            ];
        }

        return collect($days);
    }

    #[Computed]
    public function agendaForSelectedDay(): Collection
    {
        return $this->baseFollowUpsQuery()
            ->whereDate('scheduled_at', $this->cursorCarbon())
            ->with(['lead:id,full_name,phone', 'assignedUser:id,name'])
            ->orderBy('scheduled_at')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function upcomingEvents(): Collection
    {
        return $this->baseFollowUpsQuery()
            ->where('status', FollowUpStatus::Pending->value)
            ->where('scheduled_at', '>=', now())
            ->with(['lead:id,full_name', 'assignedUser:id,name'])
            ->orderBy('scheduled_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function recentEvents(): Collection
    {
        return $this->baseFollowUpsQuery()
            ->with(['lead:id,full_name', 'assignedUser:id,name'])
            ->latest('updated_at')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function leads(): Collection
    {
        return Lead::query()
            ->visibleFor(Auth::user())
            ->where('status', RecordStatus::Active->value)
            ->orderBy('full_name')
            ->limit(150)
            ->get(['id', 'full_name', 'phone', 'assigned_user_id']);
    }

    #[Computed]
    public function advisors(): Collection
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

    public function typeIcon(string $type): string
    {
        return match ($type) {
            FollowUpType::Call->value => 'bi-telephone',
            FollowUpType::Whatsapp->value => 'bi-whatsapp',
            FollowUpType::Email->value => 'bi-envelope',
            FollowUpType::Visit->value => 'bi-house',
            FollowUpType::Meeting->value => 'bi-people',
            default => 'bi-check2',
        };
    }

    public function typeClass(string $type): string
    {
        return match ($type) {
            FollowUpType::Call->value => 'is-call',
            FollowUpType::Whatsapp->value => 'is-whatsapp',
            FollowUpType::Email->value => 'is-email',
            FollowUpType::Visit->value => 'is-visit',
            default => 'is-meeting',
        };
    }

    public function displayStatus(FollowUp $event): string
    {
        if ($event->status === FollowUpStatus::Pending->value && $event->scheduled_at?->isPast()) {
            return 'Vencido';
        }

        return $event->statusLabel();
    }

    public function statusClass(FollowUp $event): string
    {
        if ($event->status === FollowUpStatus::Pending->value && $event->scheduled_at?->isPast()) {
            return 'is-overdue';
        }

        return match ($event->status) {
            FollowUpStatus::Completed->value => 'is-completed',
            FollowUpStatus::Cancelled->value => 'is-cancelled',
            default => 'is-pending',
        };
    }

    public function eventTop(FollowUp $event): int
    {
        $minutes = (($event->scheduled_at?->hour ?? self::START_HOUR) - self::START_HOUR) * 60 + ($event->scheduled_at?->minute ?? 0);

        return max(4, min((int) ($minutes * 76 / 60) + 4, (self::END_HOUR - self::START_HOUR + 1) * 76 - 76));
    }

    public function eventHeight(FollowUp $event): int
    {
        return $event->type === FollowUpType::Meeting->value || $event->type === FollowUpType::Visit->value ? 76 : 64;
    }

    public function eventStyle(FollowUp $event): string
    {
        return 'top: '.$this->eventTop($event).'px; min-height: '.$this->eventHeight($event).'px;';
    }

    public function formatEventDate(?Carbon $date): string
    {
        if ($date === null) {
            return 'Sin fecha';
        }

        if ($date->isToday()) {
            return 'Hoy '.$date->format('H:i');
        }

        if ($date->isTomorrow()) {
            return 'Mañana '.$date->format('H:i');
        }

        return $date->format('d/m H:i');
    }

    private function baseFollowUpsQuery(): Builder
    {
        return FollowUp::query()
            ->visibleFor(Auth::user())
            ->whereHas('lead', fn (Builder $query) => $query->visibleFor(Auth::user())->where('status', RecordStatus::Active->value));
    }

    private function filteredEventsQuery(): Builder
    {
        return $this->baseFollowUpsQuery()
            ->whereBetween('scheduled_at', [$this->filterRangeStart(), $this->filterRangeEnd()])
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->assignedUser !== '', fn (Builder $query) => $query->where('assigned_user_id', (int) $this->assignedUser))
            ->when($this->type !== '', fn (Builder $query) => $query->where('type', $this->type))
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $search = trim($this->search);
                $query->whereHas('lead', function (Builder $leadQuery) use ($search): void {
                    $leadQuery->where('full_name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%');
                });
            });
    }

    private function syncDateRange(): void
    {
        $this->dateFrom = $this->rangeStart()->toDateString();
        $this->dateTo = $this->rangeEnd()->toDateString();
    }

    private function rangeStart(): Carbon
    {
        $date = $this->cursorCarbon();

        return match ($this->viewMode) {
            'day' => $date->startOfDay(),
            'month' => $date->startOfMonth(),
            default => $date->startOfWeek(),
        };
    }

    private function rangeEnd(): Carbon
    {
        $date = $this->cursorCarbon();

        return match ($this->viewMode) {
            'day' => $date->endOfDay(),
            'month' => $date->endOfMonth(),
            default => $date->endOfWeek(),
        };
    }

    public function cursorCarbon(): Carbon
    {
        return Carbon::parse($this->validDate($this->cursorDate) ?? now()->toDateString());
    }

    private function filterRangeStart(): Carbon
    {
        return Carbon::parse($this->validDate($this->dateFrom) ?? $this->rangeStart()->toDateString())->startOfDay();
    }

    private function filterRangeEnd(): Carbon
    {
        return Carbon::parse($this->validDate($this->dateTo) ?? $this->rangeEnd()->toDateString())->endOfDay();
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
};
?>

<section class="crm-page crm-calendar-page">
    <header class="crm-page-header crm-calendar-page-header">
        <div>
            <p class="crm-eyebrow">Agenda</p>
            <h1 class="crm-module-title">Calendario</h1>
            <p class="crm-module-description">Organiza llamadas, visitas, reuniones y tareas comerciales del equipo.</p>
            <p class="crm-calendar-scope"><i class="bi bi-shield-check" aria-hidden="true"></i> {{ $this->currentRoleLabel }} · Datos visibles según tu rol y equipo</p>
        </div>
        <div class="crm-actions crm-calendar-header-actions">
            <flux:button icon="calendar-days" wire:click="today">Hoy</flux:button>
            <div class="crm-calendar-view-switcher" role="group" aria-label="Vista del calendario">
                @foreach (['day' => 'Día', 'week' => 'Semana', 'month' => 'Mes'] as $mode => $label)
                    <button type="button" class="crm-calendar-view-button {{ $viewMode === $mode ? 'is-active' : '' }}" wire:click="setViewMode('{{ $mode }}')">{{ $label }}</button>
                @endforeach
            </div>
            <flux:button icon="arrow-down-tray" wire:click="exportCalendar" wire:loading.attr="disabled" wire:target="exportCalendar">Exportar</flux:button>
            @can('create', \App\Models\FollowUp::class)
                <flux:button icon="plus" variant="primary" wire:click="create">Nuevo evento</flux:button>
            @endcan
        </div>
    </header>

    <section class="crm-calendar-stats crm-grid--stats">
        @foreach ($this->metrics as $metric)
            <article class="crm-card crm-calendar-stat crm-calendar-stat--{{ $metric['tone'] }}">
                <span class="crm-calendar-stat-icon"><i class="bi {{ $metric['icon'] }}" aria-hidden="true"></i></span>
                <div><p>{{ $metric['label'] }}</p><strong>{{ is_string($metric['value']) ? $metric['value'] : number_format($metric['value']) }}</strong><small>{{ $metric['detail'] }}</small></div>
            </article>
        @endforeach
    </section>

    <section class="crm-card crm-calendar-filters">
        <div class="crm-calendar-filter-grid">
            <label><span>Desde</span><input type="date" wire:model.live="dateFrom"></label>
            <label><span>Hasta</span><input type="date" wire:model.live="dateTo"></label>
            <label><span>Estado</span><select wire:model.live="status"><option value="">Todos</option>@foreach (FollowUpStatus::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</select></label>
            <label><span>Asesor</span><select wire:model.live="assignedUser"><option value="">Todos</option>@foreach ($this->advisors as $advisor)<option value="{{ $advisor->id }}">{{ $advisor->name }}</option>@endforeach</select></label>
            <label><span>Tipo</span><select wire:model.live="type"><option value="">Todos</option>@foreach (FollowUpType::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</select></label>
            <label class="crm-calendar-filter-search"><span>Buscar</span><span class="crm-calendar-search-input"><i class="bi bi-search" aria-hidden="true"></i><input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar eventos..."></span></label>
            <button type="button" class="crm-calendar-clear" wire:click="resetFilters"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Limpiar filtros</button>
        </div>
    </section>

    <section class="crm-calendar-main-grid">
        <div class="crm-calendar-primary-column">
            <section class="crm-card crm-calendar-board-card">
                <div class="crm-calendar-board-heading"><div><h2>{{ $this->rangeLabel }}</h2><p>{{ $this->calendarEvents->count() }} eventos visibles en el periodo</p></div><div class="crm-calendar-board-nav"><button type="button" wire:click="previousPeriod" aria-label="Periodo anterior"><i class="bi bi-chevron-left"></i></button><button type="button" wire:click="today">Hoy</button><button type="button" wire:click="nextPeriod" aria-label="Siguiente periodo"><i class="bi bi-chevron-right"></i></button></div></div>

                @if ($viewMode === 'week')
                    <div class="crm-calendar-week-scroll"><div class="crm-calendar-week-grid"><div class="crm-calendar-week-header"><div class="crm-calendar-corner">Hora</div>@foreach ($this->weekDays as $day)<button type="button" class="crm-calendar-day-header {{ $day['isToday'] ? 'is-today' : '' }}" wire:click="chooseDate('{{ $day['date'] }}')"><span>{{ $day['short'] }}</span><strong>{{ $day['day'] }}</strong><small>{{ $day['month'] }}</small></button>@endforeach</div><div class="crm-calendar-week-body"><div class="crm-calendar-time-column">@foreach ($this->hours as $hour)<span>{{ str_pad((string) $hour, 2, '0', STR_PAD_LEFT) }}:00</span>@endforeach</div>@foreach ($this->weekDays as $day)<div class="crm-calendar-day-column {{ $day['isToday'] ? 'is-today' : '' }}">@foreach ($this->hours as $hour)<i style="top: {{ ($hour - 8) * 76 }}px"></i>@endforeach @foreach (($this->eventsByDay[$day['date']] ?? collect()) as $event)<article class="crm-calendar-event {{ $this->typeClass($event->type) }} {{ $this->statusClass($event) }}" style="{{ $this->eventStyle($event) }}" wire:key="calendar-event-{{ $event->id }}"><div class="crm-calendar-event-time">{{ $event->scheduled_at?->format('H:i') }}</div><strong><i class="bi {{ $this->typeIcon($event->type) }}" aria-hidden="true"></i> {{ $event->typeLabel() }}</strong><span>{{ $event->lead?->full_name ?? 'Lead sin nombre' }}</span><small>{{ $event->assignedUser?->name ?? 'Sin asesor' }}</small><div class="crm-calendar-event-actions">@can('update', $event)<button type="button" wire:click="edit({{ $event->id }})" aria-label="Editar evento"><i class="bi bi-pencil"></i></button>@if ($event->status === FollowUpStatus::Pending->value)<button type="button" wire:click="complete({{ $event->id }})" aria-label="Completar evento"><i class="bi bi-check2"></i></button>@endif @endcan</div></article>@endforeach</div>@endforeach</div></div></div>
                @elseif ($viewMode === 'month')
                    <div class="crm-calendar-month-grid"><div class="crm-calendar-month-weekdays">@foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $weekday)<span>{{ $weekday }}</span>@endforeach</div><div class="crm-calendar-month-days">@foreach ($this->monthDays as $day)<button type="button" class="crm-calendar-month-day {{ $day['isCurrentMonth'] ? '' : 'is-outside' }} {{ $day['isToday'] ? 'is-today' : '' }}" wire:click="chooseDate('{{ $day['date'] }}')"><strong>{{ $day['day'] }}</strong>@foreach (($this->eventsByDay[$day['date']] ?? collect())->take(3) as $event)<span class="crm-calendar-month-event {{ $this->typeClass($event->type) }}"><i class="bi {{ $this->typeIcon($event->type) }}"></i>{{ $event->scheduled_at?->format('H:i') }} {{ $event->lead?->full_name }}</span>@endforeach</button>@endforeach</div></div>
                @else
                    <div class="crm-calendar-day-view">@forelse ($this->agendaForSelectedDay as $event)<article class="crm-calendar-day-event {{ $this->typeClass($event->type) }}"><span class="crm-calendar-day-event-time">{{ $event->scheduled_at?->format('H:i') }}</span><span class="crm-calendar-event-icon"><i class="bi {{ $this->typeIcon($event->type) }}"></i></span><div><strong>{{ $event->typeLabel() }} · {{ $event->lead?->full_name }}</strong><p>{{ $event->note ?: 'Actividad comercial programada' }}</p><small>{{ $event->assignedUser?->name ?? 'Sin asesor' }} · {{ $event->statusLabel() }}</small></div><div class="crm-calendar-day-event-actions">@can('update', $event)<button type="button" wire:click="edit({{ $event->id }})"><i class="bi bi-pencil"></i></button>@if ($event->status === FollowUpStatus::Pending->value)<button type="button" wire:click="complete({{ $event->id }})"><i class="bi bi-check2"></i></button>@endif @endcan</div></article>@empty<div class="crm-empty"><i class="bi bi-calendar-x"></i><strong>No hay actividades para este día.</strong><span>Prueba otro día o crea un nuevo evento.</span></div>@endforelse</div>
                @endif
            </section>

            <section class="crm-card crm-calendar-recent-card"><div class="crm-calendar-section-heading"><div><h2><i class="bi bi-clock-history" aria-hidden="true"></i> Actividad reciente</h2><p>Últimas actividades registradas en el calendario.</p></div><a href="{{ route('follow-ups.index') }}" wire:navigate>Ver todas <i class="bi bi-arrow-right"></i></a></div><div class="crm-calendar-recent-table"><div class="crm-calendar-recent-row crm-calendar-recent-row--head"><span>Fecha y hora</span><span>Tipo</span><span>Lead</span><span>Asesor</span><span>Estado</span></div>@forelse ($this->recentEvents as $event)<div class="crm-calendar-recent-row" wire:key="recent-calendar-{{ $event->id }}"><span>{{ $event->scheduled_at?->format('d/m/Y H:i') }}</span><span class="crm-calendar-type-cell {{ $this->typeClass($event->type) }}"><i class="bi {{ $this->typeIcon($event->type) }}"></i>{{ $event->typeLabel() }}</span><span><strong>{{ $event->lead?->full_name }}</strong><small>{{ $event->lead?->phone }}</small></span><span>{{ $event->assignedUser?->name ?? 'Sin asesor' }}</span><span class="crm-calendar-status {{ $this->statusClass($event) }}">{{ $this->displayStatus($event) }}</span></div>@empty<div class="crm-empty">Todavía no hay actividad registrada.</div>@endforelse</div></section>
        </div>

        <aside class="crm-calendar-sidebar">
            <section class="crm-card crm-calendar-mini-card"><div class="crm-calendar-sidebar-heading"><h2>{{ $this->cursorCarbon()->locale('es')->translatedFormat('F Y') }}</h2><div><button type="button" wire:click="previousMonth"><i class="bi bi-chevron-left"></i></button><button type="button" wire:click="nextMonth"><i class="bi bi-chevron-right"></i></button></div></div><div class="crm-calendar-mini-weekdays">@foreach (['L', 'M', 'X', 'J', 'V', 'S', 'D'] as $weekday)<span>{{ $weekday }}</span>@endforeach</div><div class="crm-calendar-mini-days">@foreach ($this->monthDays as $day)<button type="button" class="{{ ! $day['isCurrentMonth'] ? 'is-outside' : '' }} {{ $day['isToday'] ? 'is-today' : '' }} {{ $day['date'] === $this->cursorDate ? 'is-selected' : '' }}" wire:click="chooseDate('{{ $day['date'] }}')">{{ $day['day'] }}</button>@endforeach</div></section>
            <section class="crm-card crm-calendar-agenda-card"><div class="crm-calendar-section-heading"><div><h2>Agenda del día</h2><p>{{ $this->cursorCarbon()->locale('es')->translatedFormat('l, d \d\e F \d\e Y') }}</p></div><a href="{{ route('follow-ups.index') }}" wire:navigate>Ver todos ({{ $this->agendaForSelectedDay->count() }})</a></div><div class="crm-calendar-agenda-list">@forelse ($this->agendaForSelectedDay->take(5) as $event)<article class="crm-calendar-agenda-item"><time>{{ $event->scheduled_at?->format('H:i') }}</time><span class="crm-calendar-agenda-icon {{ $this->typeClass($event->type) }}"><i class="bi {{ $this->typeIcon($event->type) }}"></i></span><div><strong>{{ $event->typeLabel() }}</strong><small>{{ $event->lead?->full_name }}</small></div><button type="button" wire:click="edit({{ $event->id }})" aria-label="Editar evento"><i class="bi bi-three-dots-vertical"></i></button></article>@empty<div class="crm-calendar-mini-empty">No hay eventos para este día.</div>@endforelse</div></section>
            <section class="crm-card crm-calendar-upcoming-card"><div class="crm-calendar-section-heading"><div><h2>Próximas actividades</h2><p>Lo que sigue en tu agenda.</p></div><a href="{{ route('follow-ups.index') }}" wire:navigate>Ver todas</a></div><div class="crm-calendar-upcoming-list">@forelse ($this->upcomingEvents as $event)<article><span class="crm-calendar-upcoming-date">{{ $event->scheduled_at?->format('d') }}<small>{{ $event->scheduled_at?->locale('es')->isoFormat('MMM') }}</small></span><span class="crm-calendar-agenda-icon {{ $this->typeClass($event->type) }}"><i class="bi {{ $this->typeIcon($event->type) }}"></i></span><div><strong>{{ $event->typeLabel() }} - {{ $event->lead?->full_name }}</strong><small>{{ $event->scheduled_at?->format('H:i') }} · {{ $event->assignedUser?->name ?? 'Sin asesor' }}</small></div></article>@empty<div class="crm-calendar-mini-empty">No hay actividades próximas.</div>@endforelse</div></section>
            <section class="crm-card crm-calendar-legend-card"><h2>Leyenda de eventos</h2><div class="crm-calendar-legend-grid">@foreach (FollowUpType::cases() as $case)<span><i class="crm-calendar-legend-dot {{ $this->typeClass($case->value) }}"></i>{{ $case->label() }}</span>@endforeach</div></section>
        </aside>
    </section>

    @if ($showForm)
        <div class="crm-calendar-modal-layer" wire:key="calendar-form-modal"><div class="crm-calendar-modal-backdrop" wire:click="closeForm"></div><section class="crm-card crm-calendar-form-modal" role="dialog" aria-modal="true" aria-labelledby="calendar-form-title"><div class="crm-calendar-form-heading"><div><p class="crm-eyebrow">Agenda comercial</p><h2 id="calendar-form-title">{{ $editingId ? 'Editar evento' : 'Nuevo evento' }}</h2><p>Programa una actividad y asígnala al responsable correspondiente.</p></div><button type="button" wire:click="closeForm" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button></div><div class="crm-calendar-form-grid"><label><span>Lead <em>*</em></span><select wire:model="form.lead_id"><option value="">Selecciona un lead</option>@foreach ($this->leads as $lead)<option value="{{ $lead->id }}">{{ $lead->full_name }} · {{ $lead->phone }}</option>@endforeach</select>@error('form.lead_id')<small>{{ $message }}</small>@enderror</label><label><span>Responsable</span><select wire:model="form.assigned_user_id">@foreach ($this->advisors as $advisor)<option value="{{ $advisor->id }}">{{ $advisor->name }}</option>@endforeach</select></label><label><span>Tipo de evento <em>*</em></span><select wire:model="form.type">@foreach (FollowUpType::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</select></label><label><span>Fecha y hora <em>*</em></span><input type="datetime-local" wire:model="form.scheduled_at">@error('form.scheduled_at')<small>{{ $message }}</small>@enderror</label><label><span>Prioridad</span><select wire:model="form.priority">@foreach (FollowUpPriority::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</select></label><label class="crm-calendar-form-note"><span>Notas</span><textarea wire:model="form.note" rows="3" placeholder="Añade contexto para el equipo..."></textarea></label></div><div class="crm-calendar-form-actions"><button type="button" class="crm-calendar-secondary-button" wire:click="closeForm">Cancelar</button><button type="button" class="crm-calendar-primary-button" wire:click="save" wire:loading.attr="disabled"><i class="bi bi-check2"></i> {{ $editingId ? 'Guardar cambios' : 'Crear evento' }}</button></div></section></div>
    @endif
</section>
