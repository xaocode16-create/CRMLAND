<?php

use App\Enums\FollowUpPriority;
use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Seguimientos')] class extends Component
{
    public string $search = '';
    public string $status = '';
    public string $priority = '';
    public bool $showForm = false;
    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('viewAny', FollowUp::class);
        $this->resetForm();
    }

    public function resetForm(): void
    {
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

    public function save(): void
    {
        Gate::authorize('create', FollowUp::class);

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

        FollowUp::query()->create([
            'lead_id' => $lead->id,
            'assigned_user_id' => $assignedUserId,
            'type' => $validated['type'],
            'scheduled_at' => $validated['scheduled_at'],
            'priority' => $validated['priority'],
            'note' => $validated['note'] ?: null,
        ]);

        $lead->update(['next_follow_up_at' => $validated['scheduled_at']]);
        $this->showForm = false;
        $this->resetForm();
        Flux::toast(variant: 'success', text: 'Seguimiento programado.');
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

    #[Computed]
    public function followUps(): \Illuminate\Support\Collection
    {
        return FollowUp::query()
            ->visibleFor(Auth::user())
            ->with(['lead.pipelineStatus', 'assignedUser'])
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->priority !== '', fn ($query) => $query->where('priority', $this->priority))
            ->when($this->search !== '', fn ($query) => $query->whereHas('lead', function ($query): void {
                $query->where('full_name', 'like', '%'.$this->search.'%')
                    ->orWhere('phone', 'like', '%'.$this->search.'%');
            }))
            ->orderBy('scheduled_at')
            ->limit(120)
            ->get();
    }

    #[Computed]
    public function leads(): \Illuminate\Support\Collection
    {
        return Lead::query()
            ->visibleFor(Auth::user())
            ->orderBy('full_name')
            ->limit(120)
            ->get(['id', 'full_name', 'phone', 'assigned_user_id']);
    }

    #[Computed]
    public function advisors(): \Illuminate\Support\Collection
    {
        $user = Auth::user();

        return User::query()
            ->where('status', 'active')
            ->when($user->isSupervisor(), fn ($query) => $query->where(function ($query) use ($user): void {
                $query->whereKey($user->id)->orWhere('supervisor_user_id', $user->id);
            }))
            ->when($user->isAsesor(), fn ($query) => $query->whereKey($user->id))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Comercial</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Seguimientos</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Tareas comerciales por lead, asesor, prioridad y fecha.</p>
        </div>
        <div class="crm-actions">
            <flux:button icon="calendar-days" :href="route('calendar.index')" wire:navigate>Calendario</flux:button>
            @can('create', \App\Models\FollowUp::class)
                <flux:button icon="plus" variant="primary" wire:click="create">Nuevo seguimiento</flux:button>
            @endcan
        </div>
    </header>

    <section class="crm-card">
        <div class="crm-toolbar">
            <flux:input wire:model.live.debounce.350ms="search" label="Buscar lead" placeholder="Nombre o telefono" />
            <flux:select wire:model.live="status" label="Estado"><option value="">Todos</option>@foreach (FollowUpStatus::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="priority" label="Prioridad"><option value="">Todas</option>@foreach (FollowUpPriority::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
        </div>
    </section>

    @if ($showForm)
        <section class="crm-card crm-panel-pad">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div><p class="crm-eyebrow">Agenda</p><h2 class="text-lg font-bold text-[var(--color-crm-navy)]">Nuevo seguimiento</h2></div>
                <flux:button icon="x-mark" wire:click="$set('showForm', false)">Cerrar</flux:button>
            </div>
            <form wire:submit="save" class="space-y-4">
                <div class="crm-form-grid">
                    <flux:select wire:model="form.lead_id" label="Lead" required>
                        <option value="">Seleccionar</option>
                        @foreach ($this->leads as $lead)
                            <option value="{{ $lead->id }}">{{ $lead->full_name }} - {{ $lead->phone }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="form.assigned_user_id" label="Asesor">
                        <option value="">Usar asesor del lead</option>
                        @foreach ($this->advisors as $advisor)
                            <option value="{{ $advisor->id }}">{{ $advisor->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="form.type" label="Tipo">@foreach (FollowUpType::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
                    <flux:select wire:model="form.priority" label="Prioridad">@foreach (FollowUpPriority::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
                    <flux:input wire:model="form.scheduled_at" type="datetime-local" label="Fecha" required />
                </div>
                <flux:textarea wire:model="form.note" label="Nota" />
                <div class="crm-form-actions">
                    <flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary">Programar</flux:button>
                </div>
            </form>
        </section>
    @endif

    <section class="crm-card crm-panel-pad">
        <div class="crm-table-wrap">
            <table class="crm-table">
                <thead><tr><th>Fecha</th><th>Lead</th><th>Tipo</th><th>Prioridad</th><th>Asesor</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                    @forelse ($this->followUps as $item)
                        <tr wire:key="follow-up-{{ $item->id }}">
                            <td>{{ $item->scheduled_at?->format('d/m/Y H:i') }}</td>
                            <td><a class="font-semibold text-[var(--color-crm-blue)]" href="{{ route('leads.show', $item->lead) }}" wire:navigate>{{ $item->lead?->full_name }}</a><br><span class="text-xs text-[var(--color-crm-muted)]">{{ $item->lead?->phone }}</span></td>
                            <td>{{ FollowUpType::tryFrom($item->type)?->label() ?? $item->type }}</td>
                            <td><span class="crm-badge {{ $item->priority === 'HIGH' || $item->priority === 'URGENT' ? 'crm-badge--warning' : '' }}">{{ $item->priorityLabel() }}</span></td>
                            <td>{{ $item->assignedUser?->name ?? 'Sin asesor' }}</td>
                            <td><span class="crm-badge {{ $item->status === 'COMPLETED' ? 'crm-badge--success' : ($item->status === 'CANCELLED' ? 'crm-badge--danger' : 'crm-badge--info') }}">{{ $item->statusLabel() }}</span></td>
                            <td>
                                @if ($item->status === 'PENDING')
                                    <div class="crm-actions">
                                        <flux:button size="sm" wire:click="complete({{ $item->id }})">Completar</flux:button>
                                        <flux:button size="sm" wire:click="cancel({{ $item->id }})">Cancelar</flux:button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="crm-empty">No hay seguimientos con estos filtros.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</section>
