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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Seguimientos')] class extends Component
{
    private const PAGE_SIZE = 10;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $priority = '';

    #[Url]
    public string $assignedUser = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public string $tab = 'all';

    #[Url]
    public int $page = 1;

    #[Url]
    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('viewAny', FollowUp::class);
        Auth::user()->loadMissing('role');

        if ($this->showForm) {
            Gate::authorize('create', FollowUp::class);
        }

        $this->resetForm();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'priority', 'assignedUser', 'type', 'dateFrom', 'dateTo'], true)) {
            $this->page = 1;
        }
    }

    public function setTab(string $tab): void
    {
        if (! array_key_exists($tab, $this->followUpTabs)) {
            return;
        }

        $this->tab = $tab;
        $this->page = 1;
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->priority = '';
        $this->assignedUser = '';
        $this->type = '';
        $this->dateFrom = '';
        $this->dateTo = '';
        $this->tab = 'all';
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, min($page, $this->totalPages));
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
            Flux::toast(variant: 'success', text: 'Seguimiento actualizado.');
        } else {
            FollowUp::query()->create($data);
            Flux::toast(variant: 'success', text: 'Seguimiento programado.');
        }

        $lead->update(['next_follow_up_at' => $validated['scheduled_at']]);
        $this->showForm = false;
        $this->resetForm();
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
        Flux::toast(variant: 'success', text: 'Seguimiento completado.');
    }

    public function cancel(int $followUpId): void
    {
        $followUp = FollowUp::query()->findOrFail($followUpId);
        Gate::authorize('update', $followUp);
        $followUp->update(['status' => FollowUpStatus::Cancelled->value]);
        Flux::toast(variant: 'success', text: 'Seguimiento cancelado.');
    }

    public function exportFollowUps(): StreamedResponse
    {
        Gate::authorize('viewAny', FollowUp::class);

        $followUps = $this->filteredFollowUpsQuery()
            ->with(['lead', 'assignedUser'])
            ->orderBy('scheduled_at')
            ->limit(5000)
            ->get();

        return response()->streamDownload(function () use ($followUps): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Fecha', 'Lead', 'Telefono', 'Tipo', 'Prioridad', 'Asesor', 'Estado', 'Nota'], ';');

            foreach ($followUps as $followUp) {
                fputcsv($handle, [
                    $followUp->scheduled_at?->format('d/m/Y H:i'),
                    $followUp->lead?->full_name,
                    $followUp->lead?->phone,
                    $followUp->typeLabel(),
                    $followUp->priorityLabel(),
                    $followUp->assignedUser?->name ?? 'Sin asesor',
                    $this->displayStatus($followUp),
                    $followUp->note,
                ], ';');
            }

            fclose($handle);
        }, 'seguimientos-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    #[Computed]
    public function currentRoleLabel(): string
    {
        return Auth::user()->role?->name ?? 'Asesor';
    }

    #[Computed]
    public function followUps(): \Illuminate\Support\Collection
    {
        return $this->filteredFollowUpsQuery()
            ->with(['lead.pipelineStatus', 'assignedUser'])
            ->orderBy('scheduled_at')
            ->forPage($this->page, self::PAGE_SIZE)
            ->get();
    }

    #[Computed]
    public function followUpCount(): int
    {
        return $this->filteredFollowUpsQuery()->count();
    }

    #[Computed]
    public function totalPages(): int
    {
        return max(1, (int) ceil($this->followUpCount / self::PAGE_SIZE));
    }

    #[Computed]
    public function pageLinks(): array
    {
        $lastPage = $this->totalPages;
        $start = max(1, min($this->page - 2, $lastPage - 4));

        return range($start, min($lastPage, $start + 4));
    }

    #[Computed]
    public function followUpTabs(): array
    {
        $query = $this->baseFollowUpsQuery();
        $today = now();

        return [
            'all' => ['label' => 'Todos', 'count' => (clone $query)->count(), 'icon' => 'bi-list-check'],
            'today' => ['label' => 'Hoy', 'count' => (clone $query)->whereDate('scheduled_at', $today)->count(), 'icon' => 'bi-calendar-check'],
            'pending' => ['label' => 'Pendientes', 'count' => (clone $query)->where('status', FollowUpStatus::Pending->value)->count(), 'icon' => 'bi-clock'],
            'overdue' => ['label' => 'Vencidos', 'count' => (clone $query)->where('status', FollowUpStatus::Pending->value)->where('scheduled_at', '<', now())->count(), 'icon' => 'bi-exclamation-circle'],
            'completed' => ['label' => 'Completados', 'count' => (clone $query)->where('status', FollowUpStatus::Completed->value)->count(), 'icon' => 'bi-check-circle'],
        ];
    }

    #[Computed]
    public function metrics(): array
    {
        $query = $this->baseFollowUpsQuery();
        $today = now();
        $yesterday = $today->copy()->subDay();

        $todayCount = (clone $query)->whereDate('scheduled_at', $today)->count();
        $yesterdayCount = (clone $query)->whereDate('scheduled_at', $yesterday)->count();
        $pending = (clone $query)->where('status', FollowUpStatus::Pending->value)->count();
        $pendingYesterday = (clone $query)->where('status', FollowUpStatus::Pending->value)->whereDate('scheduled_at', $yesterday)->count();
        $overdue = (clone $query)->where('status', FollowUpStatus::Pending->value)->where('scheduled_at', '<', $today)->count();
        $completed = (clone $query)->where('status', FollowUpStatus::Completed->value)->count();
        $completedYesterday = (clone $query)->where('status', FollowUpStatus::Completed->value)->whereDate('completed_at', $yesterday)->count();
        $totalClosed = $completed + $pending;
        $compliance = $totalClosed > 0 ? (int) round(($completed / $totalClosed) * 100) : 0;

        return [
            ['key' => 'today', 'label' => 'Seguimientos de hoy', 'value' => number_format($todayCount), 'change' => $this->percentageChange($todayCount, $yesterdayCount), 'comparison' => 'vs. ayer', 'icon' => 'bi-calendar-check', 'tone' => 'blue'],
            ['key' => 'pending', 'label' => 'Pendientes', 'value' => number_format($pending), 'change' => $this->percentageChange($pending, $pendingYesterday), 'comparison' => 'vs. ayer', 'icon' => 'bi-clock', 'tone' => 'amber'],
            ['key' => 'overdue', 'label' => 'Vencidos', 'value' => number_format($overdue), 'change' => $this->percentageChange($overdue, $pendingYesterday), 'comparison' => 'vs. ayer', 'icon' => 'bi-exclamation-triangle', 'tone' => 'red'],
            ['key' => 'completed', 'label' => 'Completados', 'value' => number_format($completed), 'change' => $this->percentageChange($completed, $completedYesterday), 'comparison' => 'vs. ayer', 'icon' => 'bi-check-circle', 'tone' => 'green'],
            ['key' => 'compliance', 'label' => 'Cumplimiento', 'value' => $compliance.'%', 'change' => $this->percentageChange($compliance, 100), 'comparison' => 'del periodo', 'icon' => 'bi-bar-chart', 'tone' => 'cyan'],
        ];
    }

    #[Computed]
    public function leads(): \Illuminate\Support\Collection
    {
        return Lead::query()
            ->visibleFor(Auth::user())
            ->where('status', RecordStatus::Active->value)
            ->orderBy('full_name')
            ->limit(150)
            ->get(['id', 'full_name', 'phone', 'assigned_user_id']);
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
    public function recentFollowUps(): \Illuminate\Support\Collection
    {
        return $this->baseFollowUpsQuery()
            ->with(['lead:id,full_name,phone', 'assignedUser:id,name'])
            ->latest('updated_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function agendaToday(): \Illuminate\Support\Collection
    {
        return $this->baseFollowUpsQuery()
            ->where('status', FollowUpStatus::Pending->value)
            ->whereDate('scheduled_at', now())
            ->with(['lead:id,full_name', 'assignedUser:id,name'])
            ->orderBy('scheduled_at')
            ->limit(6)
            ->get();
    }

    #[Computed]
    public function alerts(): array
    {
        $query = $this->baseFollowUpsQuery();

        return [
            ['label' => 'Seguimientos vencidos', 'description' => 'Requieren atención inmediata', 'value' => (clone $query)->where('status', FollowUpStatus::Pending->value)->where('scheduled_at', '<', now())->count(), 'icon' => 'bi-exclamation-triangle', 'tone' => 'red'],
            ['label' => 'Llamadas por realizar', 'description' => 'Pendientes para hoy', 'value' => (clone $query)->where('status', FollowUpStatus::Pending->value)->where('type', FollowUpType::Call->value)->whereDate('scheduled_at', now())->count(), 'icon' => 'bi-telephone', 'tone' => 'amber'],
            ['label' => 'Visitas programadas hoy', 'description' => 'Confirma con los clientes', 'value' => (clone $query)->where('status', FollowUpStatus::Pending->value)->where('type', FollowUpType::Visit->value)->whereDate('scheduled_at', now())->count(), 'icon' => 'bi-house', 'tone' => 'blue'],
            ['label' => 'Pendientes de respuesta', 'description' => 'Leads que esperan tu respuesta', 'value' => (clone $query)->where('status', FollowUpStatus::Pending->value)->whereIn('type', [FollowUpType::Email->value, FollowUpType::Whatsapp->value])->count(), 'icon' => 'bi-envelope', 'tone' => 'slate'],
        ];
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

    public function statusClass(FollowUp $followUp): string
    {
        return $this->isOverdue($followUp) ? 'is-overdue' : match ($followUp->status) {
            FollowUpStatus::Completed->value => 'is-completed',
            FollowUpStatus::Cancelled->value => 'is-cancelled',
            default => 'is-pending',
        };
    }

    public function priorityClass(string $priority): string
    {
        return match ($priority) {
            FollowUpPriority::High->value, FollowUpPriority::Urgent->value => 'is-high',
            FollowUpPriority::Low->value => 'is-low',
            default => 'is-medium',
        };
    }

    public function displayStatus(FollowUp $followUp): string
    {
        if ($this->isOverdue($followUp)) {
            return 'Vencido';
        }

        return $followUp->statusLabel();
    }

    public function isOverdue(FollowUp $followUp): bool
    {
        return $followUp->status === FollowUpStatus::Pending->value && $followUp->scheduled_at?->isPast();
    }

    public function nextAction(FollowUp $followUp): string
    {
        if ($followUp->status === FollowUpStatus::Completed->value) {
            return 'Acción completada';
        }

        if ($followUp->status === FollowUpStatus::Cancelled->value) {
            return 'Reprogramar si corresponde';
        }

        return $this->followUpDateLabel($followUp->scheduled_at);
    }

    public function followUpDateLabel(?Carbon $date): string
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

        return $date->format('d/m/Y H:i');
    }

    public function avatarTone(int $id): string
    {
        return ['blue', 'purple', 'pink', 'green', 'orange'][$id % 5];
    }

    private function baseFollowUpsQuery(): Builder
    {
        return FollowUp::query()
            ->visibleFor(Auth::user())
            ->whereHas('lead', fn (Builder $query) => $query->visibleFor(Auth::user())->where('status', RecordStatus::Active->value));
    }

    private function filteredFollowUpsQuery(): Builder
    {
        return $this->baseFollowUpsQuery()
            ->when($this->search !== '', function (Builder $query): void {
                $search = trim($this->search);
                $query->whereHas('lead', function (Builder $leadQuery) use ($search): void {
                    $leadQuery->where('full_name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->priority !== '', fn (Builder $query) => $query->where('priority', $this->priority))
            ->when($this->assignedUser !== '', fn (Builder $query) => $query->where('assigned_user_id', (int) $this->assignedUser))
            ->when($this->type !== '', fn (Builder $query) => $query->where('type', $this->type))
            ->when($this->dateValue($this->dateFrom) !== null, fn (Builder $query) => $query->whereDate('scheduled_at', '>=', $this->dateValue($this->dateFrom)))
            ->when($this->dateValue($this->dateTo) !== null, fn (Builder $query) => $query->whereDate('scheduled_at', '<=', $this->dateValue($this->dateTo)))
            ->when($this->tab === 'today', fn (Builder $query) => $query->whereDate('scheduled_at', now()))
            ->when($this->tab === 'pending', fn (Builder $query) => $query->where('status', FollowUpStatus::Pending->value))
            ->when($this->tab === 'overdue', fn (Builder $query) => $query->where('status', FollowUpStatus::Pending->value)->where('scheduled_at', '<', now()))
            ->when($this->tab === 'completed', fn (Builder $query) => $query->where('status', FollowUpStatus::Completed->value));
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
};
?>

<section class="crm-page crm-follow-ups-page">
    <header class="crm-page-header crm-follow-ups-page-header">
        <div>
            <p class="crm-eyebrow">Comercial</p>
            <h1 class="crm-module-title">Seguimientos</h1>
            <p class="crm-module-description">Controla tareas comerciales por lead, asesor, prioridad, fecha y cumplimiento.</p>
            <p class="crm-follow-ups-scope"><i class="bi bi-shield-check" aria-hidden="true"></i> {{ $this->currentRoleLabel }} · Datos visibles según tu rol y equipo</p>
        </div>
        <div class="crm-actions crm-follow-ups-header-actions">
            <flux:button icon="calendar-days" :href="route('calendar.index')" wire:navigate>Calendario</flux:button>
            <flux:button icon="arrow-down-tray" wire:click="exportFollowUps" wire:loading.attr="disabled" wire:target="exportFollowUps">Exportar</flux:button>
            @can('create', \App\Models\FollowUp::class)
                <flux:button icon="plus" variant="primary" wire:click="create">Nuevo seguimiento</flux:button>
            @endcan
        </div>
    </header>

    <section class="crm-follow-ups-stats" aria-label="Resumen de seguimientos">
        @foreach ($this->metrics as $metric)
            <article class="crm-follow-up-stat" wire:key="follow-up-stat-{{ $metric['key'] }}">
                <span class="crm-follow-up-stat-icon is-{{ $metric['tone'] }}"><i class="bi {{ $metric['icon'] }}" aria-hidden="true"></i></span>
                <span>{{ $metric['label'] }}</span>
                <strong>{{ $metric['value'] }}</strong>
                <small><i class="bi bi-arrow-up-right" aria-hidden="true"></i> {{ $metric['change'] }} <em>{{ $metric['comparison'] }}</em></small>
            </article>
        @endforeach
    </section>

    <section class="crm-card crm-follow-ups-filters">
        <div class="crm-follow-ups-filter-heading">
            <h2><i class="bi bi-funnel" aria-hidden="true"></i> Filtros de búsqueda</h2>
            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="resetFilters">Limpiar filtros</flux:button>
        </div>
        <div class="crm-follow-ups-filter-grid">
            <flux:input wire:model.live.debounce.350ms="search" label="Buscar lead" placeholder="Nombre o teléfono..." />
            <flux:select wire:model.live="status" label="Estado"><option value="">Todos</option>@foreach (FollowUpStatus::cases() as $case)<option value="{{ $case->value }}" wire:key="follow-status-{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="priority" label="Prioridad"><option value="">Todas</option>@foreach (FollowUpPriority::cases() as $case)<option value="{{ $case->value }}" wire:key="follow-priority-{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="assignedUser" label="Asesor"><option value="">Todos</option>@foreach ($this->advisors as $advisor)<option value="{{ $advisor->id }}" wire:key="follow-advisor-{{ $advisor->id }}">{{ $advisor->name }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="type" label="Tipo"><option value="">Todos</option>@foreach (FollowUpType::cases() as $case)<option value="{{ $case->value }}" wire:key="follow-type-{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
            <div class="crm-follow-ups-date-range"><flux:input wire:model.live="dateFrom" label="Desde" type="date" /><span>—</span><flux:input wire:model.live="dateTo" label="Hasta" type="date" /></div>
        </div>
    </section>

    @if ($showForm)
        <section class="crm-card crm-panel-pad crm-follow-up-form-card">
            <div class="crm-section-heading"><div><p class="crm-eyebrow">{{ $editingId ? 'Edición' : 'Agenda' }}</p><h2 class="crm-section-title">{{ $editingId ? 'Editar seguimiento' : 'Nuevo seguimiento' }}</h2></div><flux:button icon="x-mark" wire:click="$set('showForm', false)">Cerrar</flux:button></div>
            <form wire:submit="save" class="crm-form-stack">
                <div class="crm-form-grid">
                    <flux:select wire:model="form.lead_id" label="Lead" required><option value="">Seleccionar</option>@foreach ($this->leads as $lead)<option value="{{ $lead->id }}" wire:key="follow-form-lead-{{ $lead->id }}">{{ $lead->full_name }} - {{ $lead->phone }}</option>@endforeach</flux:select>
                    <flux:select wire:model="form.assigned_user_id" label="Asesor"><option value="">Usar asesor del lead</option>@foreach ($this->advisors as $advisor)<option value="{{ $advisor->id }}" wire:key="follow-form-advisor-{{ $advisor->id }}">{{ $advisor->name }}</option>@endforeach</flux:select>
                    <flux:select wire:model="form.type" label="Tipo">@foreach (FollowUpType::cases() as $case)<option value="{{ $case->value }}" wire:key="follow-form-type-{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
                    <flux:select wire:model="form.priority" label="Prioridad">@foreach (FollowUpPriority::cases() as $case)<option value="{{ $case->value }}" wire:key="follow-form-priority-{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
                    <flux:input wire:model="form.scheduled_at" type="datetime-local" label="Fecha y hora" required />
                </div>
                <flux:textarea wire:model="form.note" label="Nota / próxima acción" />
                <div class="crm-form-actions"><flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button><flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Guardar cambios' : 'Programar seguimiento' }}</flux:button></div>
            </form>
        </section>
    @endif

    <nav class="crm-follow-up-tabs" aria-label="Segmentos de seguimientos">
        @foreach ($this->followUpTabs as $key => $tabItem)
            <button type="button" class="crm-follow-up-tab {{ $tab === $key ? 'is-active' : '' }}" wire:click="setTab('{{ $key }}')" wire:key="follow-tab-{{ $key }}" aria-current="{{ $tab === $key ? 'page' : 'false' }}"><i class="bi {{ $tabItem['icon'] }}" aria-hidden="true"></i><span>{{ $tabItem['label'] }}</span><strong>{{ number_format($tabItem['count']) }}</strong></button>
        @endforeach
    </nav>

    <section class="crm-card crm-follow-ups-table-card">
        <div class="crm-follow-ups-table-heading"><div><h2>Seguimientos ({{ number_format($this->followUpCount) }})</h2><p>Controla cada tarea y registra la próxima acción comercial.</p></div><span><i class="bi bi-database-check" aria-hidden="true"></i> Datos actualizados</span></div>
        <div class="crm-table-wrap crm-follow-ups-table-wrap">
            <table class="crm-table crm-follow-ups-table">
                <thead><tr><th aria-label="Seleccionar"></th><th>Fecha / hora</th><th>Lead</th><th>Tipo</th><th>Prioridad</th><th>Asesor</th><th>Canal</th><th>Estado</th><th>Próxima acción</th><th>Acciones</th></tr></thead>
                <tbody>
                    @forelse ($this->followUps as $item)
                        <tr wire:key="follow-up-row-{{ $item->id }}">
                            <td><input class="crm-follow-up-checkbox" type="checkbox" aria-label="Seleccionar seguimiento"></td>
                            <td><strong class="crm-follow-up-date">{{ $item->scheduled_at?->format('d/m/Y') }}</strong><small>{{ $item->scheduled_at?->format('H:i') }}</small></td>
                            <td><a class="crm-follow-up-person" href="{{ route('leads.show', $item->lead) }}" wire:navigate><span class="crm-follow-up-avatar is-{{ $this->avatarTone($item->lead_id) }}">{{ Str::initials($item->lead?->full_name ?? 'Lead', true) }}</span><span><strong>{{ $item->lead?->full_name ?? 'Lead eliminado' }}</strong><small>{{ $item->lead?->phone }}</small></span></a></td>
                            <td><span class="crm-follow-up-type {{ $this->typeClass($item->type) }}"><i class="bi {{ $this->typeIcon($item->type) }}" aria-hidden="true"></i>{{ $item->typeLabel() }}</span></td>
                            <td><span class="crm-follow-up-priority {{ $this->priorityClass($item->priority) }}"><i class="bi bi-person-fill" aria-hidden="true"></i>{{ $item->priorityLabel() }}</span></td>
                            <td><span class="crm-follow-up-assignee"><i class="bi bi-person-circle" aria-hidden="true"></i>{{ $item->assignedUser?->name ?? 'Sin asesor' }}</span></td>
                            <td><span class="crm-follow-up-channel"><i class="bi {{ $this->typeIcon($item->type) }}" aria-hidden="true"></i>{{ $item->typeLabel() }}</span></td>
                            <td><span class="crm-follow-up-status {{ $this->statusClass($item) }}"><i class="bi bi-circle-fill" aria-hidden="true"></i>{{ $this->displayStatus($item) }}</span></td>
                            <td><span class="crm-follow-up-next-action">{{ $this->nextAction($item) }}</span></td>
                            <td><div class="crm-follow-up-actions"><flux:button size="sm" icon="eye" :href="route('leads.show', $item->lead)" wire:navigate title="Ver lead" aria-label="Ver lead"></flux:button>@can('update', $item)<flux:button size="sm" icon="pencil-square" wire:click="edit({{ $item->id }})" title="Editar seguimiento" aria-label="Editar seguimiento"></flux:button>@if ($item->status === FollowUpStatus::Pending->value)<flux:button size="sm" icon="check" wire:click="complete({{ $item->id }})" title="Completar" aria-label="Completar seguimiento"></flux:button><flux:button size="sm" icon="x-mark" wire:click="cancel({{ $item->id }})" wire:confirm="¿Cancelar este seguimiento?" title="Cancelar" aria-label="Cancelar seguimiento"></flux:button>@endif @endcan</div></td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><div class="crm-empty"><i class="bi bi-calendar-x" aria-hidden="true"></i><strong>No hay seguimientos con estos filtros.</strong><span>Prueba limpiando los filtros o cambia de segmento.</span></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <footer class="crm-follow-ups-pagination"><span>Mostrando {{ $this->followUps->count() }} de {{ number_format($this->followUpCount) }} seguimientos</span><div class="crm-follow-ups-pagination-buttons"><button type="button" wire:click="goToPage({{ max(1, $page - 1) }})" @disabled($page <= 1) aria-label="Página anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>@foreach ($this->pageLinks as $pageLink)<button type="button" class="{{ $page === $pageLink ? 'is-active' : '' }}" wire:click="goToPage({{ $pageLink }})" wire:key="follow-page-{{ $pageLink }}">{{ $pageLink }}</button>@endforeach<button type="button" wire:click="goToPage({{ min($this->totalPages, $page + 1) }})" @disabled($page >= $this->totalPages) aria-label="Página siguiente"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></div></footer>
    </section>

    <div class="crm-follow-ups-bottom-grid">
        <section class="crm-card crm-follow-ups-list-card"><div class="crm-follow-ups-card-heading"><div><h2><i class="bi bi-clock-history" aria-hidden="true"></i> Actividad reciente</h2><p>Últimas acciones registradas.</p></div><flux:button size="sm" variant="ghost" wire:click="$set('tab', 'all')">Ver todas <i class="bi bi-arrow-right" aria-hidden="true"></i></flux:button></div><div class="crm-follow-ups-activity-list">@forelse ($this->recentFollowUps as $item)<article class="crm-follow-up-activity" wire:key="recent-follow-{{ $item->id }}"><span class="crm-follow-up-activity-icon is-{{ $this->avatarTone($item->id) }}"><i class="bi {{ $this->typeIcon($item->type) }}" aria-hidden="true"></i></span><div><strong>{{ $item->assignedUser?->name ?? 'Sistema' }}</strong><span>{{ $item->typeLabel() }} con {{ $item->lead?->full_name }}</span><small>{{ $item->updated_at?->diffForHumans() }}</small></div></article>@empty<div class="crm-follow-ups-mini-empty">No hay actividad reciente.</div>@endforelse</div></section>
        <section class="crm-card crm-follow-ups-list-card"><div class="crm-follow-ups-card-heading"><div><h2><i class="bi bi-bell" aria-hidden="true"></i> Alertas y pendientes</h2><p>Prioridades que requieren atención.</p></div><flux:button size="sm" variant="ghost" wire:click="setTab('overdue')">Ver vencidos <i class="bi bi-arrow-right" aria-hidden="true"></i></flux:button></div><div class="crm-follow-ups-alert-list">@foreach ($this->alerts as $alert)<article class="crm-follow-up-alert" wire:key="follow-alert-{{ $alert['label'] }}"><span class="crm-follow-up-alert-icon is-{{ $alert['tone'] }}"><i class="bi {{ $alert['icon'] }}" aria-hidden="true"></i></span><div><strong>{{ number_format($alert['value']) }} {{ $alert['label'] }}</strong><small>{{ $alert['description'] }}</small></div><i class="bi bi-chevron-right" aria-hidden="true"></i></article>@endforeach</div></section>
        <section class="crm-card crm-follow-ups-list-card"><div class="crm-follow-ups-card-heading"><div><h2><i class="bi bi-calendar3" aria-hidden="true"></i> Agenda del día</h2><p>{{ now()->translatedFormat('l, d \d\e F \d\e Y') }}</p></div><flux:button size="sm" variant="ghost" :href="route('calendar.index')" wire:navigate>Ver calendario <i class="bi bi-arrow-right" aria-hidden="true"></i></flux:button></div><div class="crm-follow-ups-agenda-list">@forelse ($this->agendaToday as $item)<article class="crm-follow-up-agenda" wire:key="agenda-follow-{{ $item->id }}"><time>{{ $item->scheduled_at?->format('H:i') }}</time><span class="crm-follow-up-agenda-icon {{ $this->typeClass($item->type) }}"><i class="bi {{ $this->typeIcon($item->type) }}" aria-hidden="true"></i></span><div><strong>{{ $item->typeLabel() }} - {{ $item->lead?->full_name }}</strong><small>{{ $item->note ?: 'Seguimiento programado' }}</small></div></article>@empty<div class="crm-follow-ups-mini-empty">No hay eventos pendientes para hoy.</div>@endforelse</div></section>
    </div>
</section>
