<?php

use App\Actions\Leads\CreateLeadAction;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Enums\RecordStatus;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadSource;
use App\Models\LeadStatus;
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

new #[Title('Leads')] class extends Component
{
    private const PAGE_SIZE = 10;

    /** @var string[] */
    private const FILTER_FIELDS = ['search', 'source', 'stage', 'district', 'assignedUser', 'dateFrom', 'dateTo'];

    #[Url]
    public string $search = '';

    #[Url]
    public string $source = '';

    #[Url]
    public string $stage = '';

    #[Url]
    public string $district = '';

    #[Url]
    public string $assignedUser = '';

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
        Gate::authorize('viewAny', Lead::class);

        $user = Auth::user();
        $user->loadMissing('role');

        if ($this->showForm) {
            Gate::authorize('create', Lead::class);
        }

        $this->resetForm();
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTER_FIELDS, true)) {
            $this->page = 1;
        }
    }

    public function setTab(string $tab): void
    {
        if (! array_key_exists($tab, $this->leadTabs)) {
            return;
        }

        $this->tab = $tab;
        $this->page = 1;
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->source = '';
        $this->stage = '';
        $this->district = '';
        $this->assignedUser = '';
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
            'first_name' => '',
            'last_name' => '',
            'phone' => '',
            'email' => '',
            'source_id' => LeadSource::query()->oldest('id')->value('id'),
            'status_id' => LeadStatus::query()->oldest('sort_order')->value('id'),
            'assigned_user_id' => null,
            'district' => '',
            'property_type' => '',
            'budget_min' => null,
            'budget_max' => null,
            'budget_currency' => 'PEN',
            'notes' => '',
        ];
    }

    public function create(): void
    {
        Gate::authorize('create', Lead::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $leadId): void
    {
        $lead = Lead::query()->findOrFail($leadId);
        Gate::authorize('update', $lead);

        $this->editingId = $lead->id;
        $this->form = [
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'source_id' => $lead->source_id,
            'status_id' => $lead->status_id,
            'assigned_user_id' => $lead->assigned_user_id,
            'district' => $lead->district,
            'property_type' => $lead->property_type,
            'budget_min' => $lead->budget_min,
            'budget_max' => $lead->budget_max,
            'budget_currency' => $lead->budget_currency ?: 'PEN',
            'notes' => $lead->notes,
        ];
        $this->showForm = true;
    }

    public function save(CreateLeadAction $createLead): void
    {
        $lead = $this->editingId ? Lead::query()->findOrFail($this->editingId) : null;
        $lead ? Gate::authorize('update', $lead) : Gate::authorize('create', Lead::class);

        $validated = $this->validate([
            'form.first_name' => ['required', 'string', 'max:120'],
            'form.last_name' => ['nullable', 'string', 'max:120'],
            'form.phone' => ['required', 'string', 'max:30', Rule::unique('leads', 'phone')->ignore($this->editingId)],
            'form.email' => ['nullable', 'email', 'max:255', Rule::unique('leads', 'email')->ignore($this->editingId)],
            'form.source_id' => ['required', 'integer', 'exists:lead_sources,id'],
            'form.status_id' => ['required', 'integer', 'exists:lead_statuses,id'],
            'form.assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'form.district' => ['nullable', 'string', 'max:120'],
            'form.property_type' => ['nullable', 'string', 'max:120'],
            'form.budget_min' => ['nullable', 'numeric', 'min:0'],
            'form.budget_max' => ['nullable', 'numeric', 'min:0'],
            'form.budget_currency' => ['required', Rule::in(['PEN', 'USD'])],
            'form.notes' => ['nullable', 'string', 'max:1000'],
        ])['form'];

        $assignedUserId = $validated['assigned_user_id'] !== null ? (int) $validated['assigned_user_id'] : null;
        abort_unless(Auth::user()->canAssignTo($assignedUserId), 403);

        if ($lead instanceof Lead) {
            $validated['full_name'] = trim($validated['first_name'].' '.($validated['last_name'] ?? ''));
            $lead->update($validated);
            Flux::toast(variant: 'success', text: 'Lead actualizado.');
        } else {
            $createLead->handle($validated, Auth::user());
            Flux::toast(variant: 'success', text: 'Lead creado.');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function disable(int $leadId): void
    {
        $lead = Lead::query()->findOrFail($leadId);
        Gate::authorize('delete', $lead);
        $lead->update(['status' => RecordStatus::Inactive->value]);
        $lead->delete();
        Flux::toast(variant: 'success', text: 'Lead desactivado.');
    }

    public function exportLeads(): StreamedResponse
    {
        Gate::authorize('viewAny', Lead::class);

        $leads = $this->filteredLeadsQuery()
            ->with(['source', 'pipelineStatus', 'assignedUser'])
            ->latest('entered_at')
            ->limit(5000)
            ->get();

        return response()->streamDownload(function () use ($leads): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Lead', 'Telefono', 'Correo', 'Origen', 'Etapa', 'Distrito', 'Presupuesto', 'Asesor', 'Ingreso'], ';');

            foreach ($leads as $lead) {
                fputcsv($handle, [
                    $lead->full_name,
                    $lead->phone,
                    $lead->email,
                    $lead->source?->name,
                    $lead->pipelineStatus?->name,
                    $lead->district,
                    $lead->budgetDisplay(),
                    $lead->assignedUser?->name ?? 'Sin asignar',
                    $lead->entered_at?->format('d/m/Y H:i'),
                ], ';');
            }

            fclose($handle);
        }, 'leads-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    #[Computed]
    public function currentRoleLabel(): string
    {
        return Auth::user()->role?->name ?? 'Asesor';
    }

    #[Computed]
    public function leadStats(): array
    {
        $query = $this->visibleLeadsQuery();
        $today = now();
        $currentMonthStart = $today->copy()->startOfMonth();
        $currentMonthEnd = $today->copy()->endOfMonth();
        $previousMonthStart = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $previousMonthEnd = $today->copy()->subMonthNoOverflow()->endOfMonth();

        $currentMonthQuery = fn (): Builder => (clone $query)->whereBetween('entered_at', [$currentMonthStart, $currentMonthEnd]);
        $previousMonthQuery = fn (): Builder => (clone $query)->whereBetween('entered_at', [$previousMonthStart, $previousMonthEnd]);
        $qualifiedStatuses = ['appointment', 'negotiation', 'closed_won'];

        $total = (clone $query)->count();
        $currentMonthTotal = $currentMonthQuery()->count();
        $previousMonthTotal = $previousMonthQuery()->count();
        $newToday = (clone $query)->whereDate('entered_at', $today)->count();
        $newYesterday = (clone $query)->whereDate('entered_at', $today->copy()->subDay())->count();
        $followUp = (clone $query)->whereHas('pipelineStatus', fn (Builder $statusQuery) => $statusQuery->where('slug', 'follow_up'))->count();
        $qualified = (clone $query)->whereHas('pipelineStatus', fn (Builder $statusQuery) => $statusQuery->whereIn('slug', $qualifiedStatuses))->count();
        $won = (clone $query)->whereHas('pipelineStatus', fn (Builder $statusQuery) => $statusQuery->where('slug', 'closed_won'))->count();
        $currentMonthWon = $currentMonthQuery()->whereHas('pipelineStatus', fn (Builder $statusQuery) => $statusQuery->where('slug', 'closed_won'))->count();
        $previousMonthWon = $previousMonthQuery()->whereHas('pipelineStatus', fn (Builder $statusQuery) => $statusQuery->where('slug', 'closed_won'))->count();

        return [
            ['key' => 'total', 'label' => 'Leads totales', 'value' => number_format($total), 'change' => $this->percentageChange($currentMonthTotal, $previousMonthTotal), 'comparison' => 'vs. mes anterior', 'icon' => 'bi-people', 'tone' => 'blue'],
            ['key' => 'today', 'label' => 'Nuevos hoy', 'value' => number_format($newToday), 'change' => $this->percentageChange($newToday, $newYesterday), 'comparison' => 'desde ayer', 'icon' => 'bi-plus-lg', 'tone' => 'cyan'],
            ['key' => 'follow-up', 'label' => 'En seguimiento', 'value' => number_format($followUp), 'change' => $this->percentageChange($followUp, $total), 'comparison' => 'leads activos', 'icon' => 'bi-clock', 'tone' => 'amber'],
            ['key' => 'qualified', 'label' => 'Calificados', 'value' => number_format($qualified), 'change' => $this->percentageChange($qualified, $total), 'comparison' => 'oportunidades', 'icon' => 'bi-check-circle', 'tone' => 'green'],
            ['key' => 'conversion', 'label' => 'Conversión', 'value' => $total > 0 ? number_format(($won / $total) * 100, 1).'%' : '0.0%', 'change' => $this->percentageChange($currentMonthWon, $previousMonthWon), 'comparison' => 'leads a clientes', 'icon' => 'bi-bar-chart', 'tone' => 'indigo'],
        ];
    }

    #[Computed]
    public function leadTabs(): array
    {
        $query = $this->visibleLeadsQuery();
        $qualifiedStatuses = ['appointment', 'negotiation', 'closed_won'];

        return [
            'all' => ['label' => 'Todos', 'count' => (clone $query)->count(), 'icon' => 'bi-people'],
            'assigned' => ['label' => 'Asignados', 'count' => (clone $query)->whereNotNull('assigned_user_id')->count(), 'icon' => 'bi-person-check'],
            'unassigned' => ['label' => 'Sin asignar', 'count' => (clone $query)->whereNull('assigned_user_id')->count(), 'icon' => 'bi-person'],
            'qualified' => ['label' => 'Calificados', 'count' => (clone $query)->whereHas('pipelineStatus', fn (Builder $statusQuery) => $statusQuery->whereIn('slug', $qualifiedStatuses))->count(), 'icon' => 'bi-check-circle'],
        ];
    }

    #[Computed]
    public function leadCount(): int
    {
        return $this->filteredLeadsQuery()->count();
    }

    #[Computed]
    public function totalPages(): int
    {
        return max(1, (int) ceil($this->leadCount / self::PAGE_SIZE));
    }

    #[Computed]
    public function pageLinks(): array
    {
        $lastPage = $this->totalPages;
        $start = max(1, min($this->page - 2, $lastPage - 4));
        $end = min($lastPage, $start + 4);

        return range($start, $end);
    }

    #[Computed]
    public function leads(): \Illuminate\Support\Collection
    {
        return $this->filteredLeadsQuery()
            ->with(['source', 'pipelineStatus', 'assignedUser'])
            ->latest('entered_at')
            ->forPage($this->page, self::PAGE_SIZE)
            ->get();
    }

    #[Computed]
    public function sources(): \Illuminate\Support\Collection
    {
        return LeadSource::query()->where('status', RecordStatus::Active->value)->orderBy('name')->get();
    }

    #[Computed]
    public function statuses(): \Illuminate\Support\Collection
    {
        return LeadStatus::query()->where('status', RecordStatus::Active->value)->orderBy('sort_order')->get();
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
    public function highlights(): array
    {
        $today = now();
        $query = $this->visibleLeadsQuery();
        $followUpQuery = FollowUp::query()
            ->visibleFor(Auth::user())
            ->where('status', FollowUpStatus::Pending->value)
            ->whereHas('lead', fn (Builder $leadQuery) => $leadQuery->where('status', RecordStatus::Active->value));

        return [
            ['label' => 'Contactar hoy', 'description' => 'Leads con actividad pendiente', 'value' => (clone $query)->whereDate('next_follow_up_at', $today)->count(), 'icon' => 'bi-telephone', 'tone' => 'danger'],
            ['label' => 'Sin asignar', 'description' => 'Requieren un asesor', 'value' => (clone $query)->whereNull('assigned_user_id')->count(), 'icon' => 'bi-person-plus', 'tone' => 'amber'],
            ['label' => 'Visitas hoy', 'description' => 'Revisar en el calendario', 'value' => (clone $followUpQuery)->where('type', FollowUpType::Visit->value)->whereDate('scheduled_at', $today)->count(), 'icon' => 'bi-calendar-check', 'tone' => 'green'],
        ];
    }

    #[Computed]
    public function recentActivities(): \Illuminate\Support\Collection
    {
        return LeadActivity::query()
            ->whereHas('lead', fn (Builder $query) => $query->visibleFor(Auth::user()))
            ->with(['lead:id,full_name', 'user:id,name'])
            ->latest()
            ->limit(4)
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
            ['label' => 'Leads sin asignar', 'description' => 'Requieren un asesor responsable', 'value' => (clone $query)->whereNull('assigned_user_id')->count(), 'icon' => 'bi-people', 'tone' => 'danger'],
            ['label' => 'Sin contacto reciente', 'description' => 'Más de 24 horas sin actividad', 'value' => (clone $query)->where(function (Builder $leadQuery): void { $leadQuery->whereNull('last_contact_at')->orWhere('last_contact_at', '<', now()->subDay()); })->count(), 'icon' => 'bi-telephone-forward', 'tone' => 'green'],
            ['label' => 'Seguimientos vencidos', 'description' => 'Requieren atención inmediata', 'value' => (clone $pendingFollowUps)->where('scheduled_at', '<', now())->count(), 'icon' => 'bi-calendar-x', 'tone' => 'blue'],
            ['label' => 'Oportunidades activas', 'description' => 'En negociación o cita programada', 'value' => (clone $query)->whereHas('pipelineStatus', fn (Builder $statusQuery) => $statusQuery->whereIn('slug', ['appointment', 'negotiation']))->count(), 'icon' => 'bi-exclamation-triangle', 'tone' => 'amber'],
        ];
    }

    public function stageClass(?string $slug): string
    {
        return match ($slug) {
            'new' => 'is-sky', 'contacted' => 'is-green', 'follow_up' => 'is-amber', 'appointment' => 'is-purple', 'negotiation' => 'is-orange', 'closed_won' => 'is-emerald', 'lost' => 'is-red', default => 'is-neutral',
        };
    }

    public function activityIcon(?string $type): string
    {
        return match ($type) {
            'CALL' => 'bi-telephone', 'WHATSAPP' => 'bi-whatsapp', 'EMAIL' => 'bi-envelope', 'VISIT' => 'bi-calendar-check', default => 'bi-plus-lg',
        };
    }

    public function avatarTone(int $id): string
    {
        return ['blue', 'purple', 'pink', 'green', 'orange'][$id % 5];
    }

    public function lastContactLabel(?Carbon $date): string
    {
        if ($date === null) {
            return 'Sin contacto';
        }

        return $date->isToday() ? 'Hoy, '.$date->format('H:i') : $date->diffForHumans();
    }

    private function visibleLeadsQuery(): Builder
    {
        return Lead::query()->visibleFor(Auth::user())->where('status', RecordStatus::Active->value);
    }

    private function filteredLeadsQuery(): Builder
    {
        $qualifiedStatuses = ['appointment', 'negotiation', 'closed_won'];

        return $this->visibleLeadsQuery()
            ->when($this->search !== '', function (Builder $query): void {
                $search = trim($this->search);
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('full_name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%');
                });
            })
            ->when($this->source !== '', fn (Builder $query) => $query->where('source_id', (int) $this->source))
            ->when($this->stage !== '', fn (Builder $query) => $query->where('status_id', (int) $this->stage))
            ->when($this->assignedUser !== '', fn (Builder $query) => $query->where('assigned_user_id', (int) $this->assignedUser))
            ->when($this->district !== '', fn (Builder $query) => $query->where('district', 'like', '%'.trim($this->district).'%'))
            ->when($this->dateValue($this->dateFrom) !== null, fn (Builder $query) => $query->whereDate('entered_at', '>=', $this->dateValue($this->dateFrom)))
            ->when($this->dateValue($this->dateTo) !== null, fn (Builder $query) => $query->whereDate('entered_at', '<=', $this->dateValue($this->dateTo)))
            ->when($this->tab === 'assigned', fn (Builder $query) => $query->whereNotNull('assigned_user_id'))
            ->when($this->tab === 'unassigned', fn (Builder $query) => $query->whereNull('assigned_user_id'))
            ->when($this->tab === 'qualified', fn (Builder $query) => $query->whereHas('pipelineStatus', fn (Builder $statusQuery) => $statusQuery->whereIn('slug', $qualifiedStatuses)));
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
        $prefix = $change >= 0 ? '+' : '';

        return $prefix.number_format($change, 1).'%';
    }
};
?>

<section class="crm-page crm-leads-page">
    <header class="crm-page-header crm-leads-page-header">
        <div>
            <p class="crm-eyebrow">Comercial</p>
            <h1 class="crm-module-title">Leads</h1>
            <p class="crm-module-description">Clientes potenciales, origen, etapa y asesor responsable.</p>
            <p class="crm-leads-scope"><i class="bi bi-shield-check" aria-hidden="true"></i> {{ $this->currentRoleLabel }} · Datos visibles según tu rol y equipo</p>
        </div>
        <div class="crm-actions crm-leads-header-actions">
            <flux:button icon="arrow-path" wire:click="$refresh" wire:loading.attr="disabled">Actualizar</flux:button>
            <flux:button icon="arrow-down-tray" wire:click="exportLeads" wire:loading.attr="disabled" wire:target="exportLeads">Exportar</flux:button>
            @can('create', \App\Models\Lead::class)
                <flux:button icon="plus" variant="primary" wire:click="create">Nuevo lead</flux:button>
            @endcan
        </div>
    </header>

    <section class="crm-leads-stats" aria-label="Resumen de leads">
        @foreach ($this->leadStats as $stat)
            <article class="crm-lead-stat-card" wire:key="lead-stat-{{ $stat['key'] }}">
                <span class="crm-lead-stat-icon is-{{ $stat['tone'] }}"><i class="bi {{ $stat['icon'] }}" aria-hidden="true"></i></span>
                <span class="crm-lead-stat-label">{{ $stat['label'] }}</span>
                <strong>{{ $stat['value'] }}</strong>
                <span class="crm-lead-stat-trend {{ str_starts_with($stat['change'], '-') ? 'is-negative' : '' }}"><i class="bi bi-arrow-up-right" aria-hidden="true"></i> {{ $stat['change'] }}</span>
                <small>{{ $stat['comparison'] }}</small>
            </article>
        @endforeach
    </section>

    <section class="crm-card crm-leads-filters">
        <div class="crm-leads-card-heading">
            <div>
                <h2 class="crm-section-title"><i class="bi bi-search" aria-hidden="true"></i> Filtros de búsqueda</h2>
                <p>Combina los filtros para encontrar rápidamente una oportunidad.</p>
            </div>
            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="resetFilters">Limpiar filtros</flux:button>
        </div>
        <div class="crm-leads-filter-grid">
            <flux:input wire:model.live.debounce.350ms="search" label="Buscar" placeholder="Nombre, teléfono o correo..." />
            <flux:select wire:model.live="source" label="Origen">
                <option value="">Todos</option>
                @foreach ($this->sources as $item)
                    <option value="{{ $item->id }}" wire:key="lead-source-filter-{{ $item->id }}">{{ $item->name }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="stage" label="Etapa">
                <option value="">Todas</option>
                @foreach ($this->statuses as $item)
                    <option value="{{ $item->id }}" wire:key="lead-stage-filter-{{ $item->id }}">{{ $item->name }}</option>
                @endforeach
            </flux:select>
            <flux:select wire:model.live="assignedUser" label="Asesor">
                <option value="">Todos</option>
                @foreach ($this->advisors as $advisor)
                    <option value="{{ $advisor->id }}" wire:key="lead-advisor-filter-{{ $advisor->id }}">{{ $advisor->name }}</option>
                @endforeach
            </flux:select>
            <flux:input wire:model.live.debounce.350ms="district" label="Zona / Distrito" placeholder="Todos" />
            <div class="crm-leads-date-range">
                <flux:input wire:model.live="dateFrom" label="Desde" type="date" />
                <span aria-hidden="true">—</span>
                <flux:input wire:model.live="dateTo" label="Hasta" type="date" />
            </div>
        </div>
    </section>

    @if ($showForm)
        <section class="crm-card crm-panel-pad crm-leads-form-card">
            <div class="crm-section-heading">
                <div><p class="crm-eyebrow">{{ $editingId ? 'Edición' : 'Registro' }}</p><h2 class="crm-section-title">{{ $editingId ? 'Editar lead' : 'Nuevo lead' }}</h2></div>
                <flux:button icon="x-mark" wire:click="$set('showForm', false)">Cerrar</flux:button>
            </div>
            <form wire:submit="save" class="crm-form-stack">
                <div class="crm-form-grid">
                    <flux:input wire:model="form.first_name" label="Nombre" required />
                    <flux:input wire:model="form.last_name" label="Apellido" />
                    <flux:input wire:model="form.phone" label="Teléfono" required />
                    <flux:input wire:model="form.email" label="Correo" type="email" />
                    <flux:select wire:model="form.source_id" label="Origen" required>@foreach ($this->sources as $item)<option value="{{ $item->id }}" wire:key="lead-form-source-{{ $item->id }}">{{ $item->name }}</option>@endforeach</flux:select>
                    <flux:select wire:model="form.status_id" label="Etapa" required>@foreach ($this->statuses as $item)<option value="{{ $item->id }}" wire:key="lead-form-stage-{{ $item->id }}">{{ $item->name }}</option>@endforeach</flux:select>
                    <flux:select wire:model="form.assigned_user_id" label="Asesor"><option value="">Sin asignar</option>@foreach ($this->advisors as $advisor)<option value="{{ $advisor->id }}" wire:key="lead-form-advisor-{{ $advisor->id }}">{{ $advisor->name }}</option>@endforeach</flux:select>
                    <flux:input wire:model="form.district" label="Distrito" />
                    <flux:input wire:model="form.property_type" label="Tipo de propiedad" />
                    <flux:input wire:model="form.budget_min" label="Presupuesto mínimo" type="number" step="0.01" />
                    <flux:input wire:model="form.budget_max" label="Presupuesto máximo" type="number" step="0.01" />
                    <flux:select wire:model="form.budget_currency" label="Moneda"><option value="PEN">PEN</option><option value="USD">USD</option></flux:select>
                </div>
                <flux:textarea wire:model="form.notes" label="Notas" />
                <div class="crm-form-actions">
                    <flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">Guardar lead</flux:button>
                </div>
            </form>
        </section>
    @endif

    <nav class="crm-leads-tabs" aria-label="Segmentos de leads">
        @foreach ($this->leadTabs as $key => $item)
            <button type="button" class="crm-leads-tab {{ $tab === $key ? 'is-active' : '' }}" wire:click="setTab('{{ $key }}')" wire:key="lead-tab-{{ $key }}" aria-current="{{ $tab === $key ? 'page' : 'false' }}">
                <i class="bi {{ $item['icon'] }}" aria-hidden="true"></i>
                <span>{{ $item['label'] }}</span>
                <strong>{{ number_format($item['count']) }}</strong>
            </button>
        @endforeach
    </nav>

    <section class="crm-leads-highlights" aria-label="Pendientes de operación">
        @foreach ($this->highlights as $highlight)
            <article class="crm-lead-highlight" wire:key="lead-highlight-{{ $highlight['label'] }}">
                <span class="crm-lead-highlight-icon is-{{ $highlight['tone'] }}"><i class="bi {{ $highlight['icon'] }}" aria-hidden="true"></i></span>
                <div><strong>{{ number_format($highlight['value']) }} {{ $highlight['label'] }}</strong><small>{{ $highlight['description'] }}</small></div>
            </article>
        @endforeach
    </section>

    <section class="crm-card crm-leads-table-card">
        <div class="crm-leads-card-heading crm-leads-table-heading">
            <div>
                <h2 class="crm-section-title">Leads ({{ number_format($this->leadCount) }})</h2>
                <p>Gestiona el seguimiento de cada oportunidad y su próximo paso comercial.</p>
            </div>
            <span class="crm-leads-live-state"><i class="bi bi-database-check" aria-hidden="true"></i> Datos actualizados desde la base de datos</span>
        </div>
        <div class="crm-table-wrap crm-leads-table-wrap">
            <table class="crm-table crm-leads-table">
                <thead>
                    <tr><th aria-label="Seleccionar"></th><th>Lead</th><th>Origen</th><th>Etapa</th><th>Zona</th><th>Presupuesto</th><th>Asesor</th><th>Último contacto</th><th>Estado</th><th>Acciones</th></tr>
                </thead>
                <tbody>
                    @forelse ($this->leads as $lead)
                        <tr wire:key="lead-row-{{ $lead->id }}">
                            <td><input class="crm-lead-checkbox" type="checkbox" aria-label="Seleccionar {{ $lead->full_name }}"></td>
                            <td>
                                <a class="crm-lead-person" href="{{ route('leads.show', $lead) }}" wire:navigate>
                                    <span class="crm-lead-avatar is-{{ $this->avatarTone($lead->id) }}">{{ Str::initials($lead->full_name, true) }}</span>
                                    <span><strong>{{ $lead->full_name }}</strong><small>{{ $lead->phone }}</small></span>
                                </a>
                            </td>
                            <td><span class="crm-lead-source">{{ $lead->source?->name ?? 'Sin origen' }}</span></td>
                            <td><span class="crm-lead-stage {{ $this->stageClass($lead->pipelineStatus?->slug) }}">{{ $lead->pipelineStatus?->name ?? 'Sin etapa' }}</span></td>
                            <td>{{ $lead->district ?: '-' }}</td>
                            <td class="crm-lead-budget">{{ $lead->budgetDisplay() }}</td>
                            <td><span class="crm-lead-assignee"><i class="bi bi-person-circle" aria-hidden="true"></i>{{ $lead->assignedUser?->name ?? 'Sin asignar' }}</span></td>
                            <td><span class="crm-lead-last-contact"><i class="bi {{ $lead->last_contact_at ? 'bi-telephone' : 'bi-dash-circle' }}" aria-hidden="true"></i>{{ $this->lastContactLabel($lead->last_contact_at) }}</span></td>
                            <td><span class="crm-lead-status"><i class="bi bi-circle-fill" aria-hidden="true"></i> Activo</span></td>
                            <td>
                                <div class="crm-lead-row-actions">
                                    <flux:button size="sm" icon="eye" :href="route('leads.show', $lead)" wire:navigate title="Ver lead" aria-label="Ver lead"></flux:button>
                                    @can('update', $lead)<flux:button size="sm" icon="pencil-square" wire:click="edit({{ $lead->id }})" title="Editar lead" aria-label="Editar lead"></flux:button>@endcan
                                    @can('leads.assign')<flux:button size="sm" icon="user-plus" wire:click="edit({{ $lead->id }})" title="Asignar lead" aria-label="Asignar lead"></flux:button>@endcan
                                    @can('delete', $lead)<flux:button size="sm" icon="archive-box" wire:click="disable({{ $lead->id }})" wire:confirm="¿Desactivar este lead?" title="Desactivar lead" aria-label="Desactivar lead"></flux:button>@endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><div class="crm-empty"><i class="bi bi-search" aria-hidden="true"></i><strong>No hay leads con estos filtros.</strong><span>Prueba limpiando los filtros o cambia de segmento.</span></div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <footer class="crm-leads-pagination">
            <span>Mostrando {{ $this->leads->count() }} de {{ number_format($this->leadCount) }} leads</span>
            <div class="crm-leads-pagination-buttons">
                <button type="button" wire:click="goToPage({{ max(1, $page - 1) }})" @disabled($page <= 1) aria-label="Página anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                @foreach ($this->pageLinks as $pageLink)
                    <button type="button" class="{{ $page === $pageLink ? 'is-active' : '' }}" wire:click="goToPage({{ $pageLink }})" wire:key="lead-page-{{ $pageLink }}">{{ $pageLink }}</button>
                @endforeach
                <button type="button" wire:click="goToPage({{ min($this->totalPages, $page + 1) }})" @disabled($page >= $this->totalPages) aria-label="Página siguiente"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
            </div>
        </footer>
    </section>

    <div class="crm-leads-bottom-grid">
        <section class="crm-card crm-leads-list-card">
            <div class="crm-leads-card-heading">
                <div><h2 class="crm-section-title"><i class="bi bi-clock-history" aria-hidden="true"></i> Actividad reciente</h2><p>Últimos movimientos del equipo comercial.</p></div>
                @can('followups.view')<flux:button size="sm" variant="ghost" :href="route('follow-ups.index')" wire:navigate>Ver todas <i class="bi bi-arrow-right" aria-hidden="true"></i></flux:button>@endcan
            </div>
            <div class="crm-leads-activity-list">
                @forelse ($this->recentActivities as $activity)
                    <article class="crm-lead-activity-row" wire:key="lead-activity-{{ $activity->id }}">
                        <span class="crm-lead-activity-icon is-{{ $this->avatarTone($activity->id) }}"><i class="bi {{ $this->activityIcon($activity->type) }}" aria-hidden="true"></i></span>
                        <div><strong>{{ $activity->user?->name ?? 'Sistema' }}</strong><span>{{ $activity->title }}</span><small>{{ $activity->lead?->full_name }} · {{ $activity->created_at?->diffForHumans() }}</small></div>
                    </article>
                @empty
                    <div class="crm-leads-mini-empty">No hay actividad reciente para mostrar.</div>
                @endforelse
            </div>
        </section>

        <section class="crm-card crm-leads-list-card">
            <div class="crm-leads-card-heading">
                <div><h2 class="crm-section-title"><i class="bi bi-bell" aria-hidden="true"></i> Alertas y pendientes</h2><p>Acciones recomendadas según tu operación.</p></div>
                @can('followups.view')<flux:button size="sm" variant="ghost" :href="route('follow-ups.index')" wire:navigate>Ver todas <i class="bi bi-arrow-right" aria-hidden="true"></i></flux:button>@endcan
            </div>
            <div class="crm-leads-alert-list">
                @foreach ($this->alerts as $alert)
                    <article class="crm-lead-alert-row" wire:key="lead-alert-{{ $alert['label'] }}">
                        <span class="crm-lead-alert-icon is-{{ $alert['tone'] }}"><i class="bi {{ $alert['icon'] }}" aria-hidden="true"></i></span>
                        <div><strong>{{ number_format($alert['value']) }} {{ $alert['label'] }}</strong><small>{{ $alert['description'] }}</small></div>
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
</section>
