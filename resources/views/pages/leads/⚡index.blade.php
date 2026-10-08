<?php

use App\Actions\Leads\CreateLeadAction;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Leads')] class extends Component
{
    #[Url]
    public string $search = '';
    public string $source = '';
    public string $stage = '';
    public string $district = '';
    #[Url]
    public bool $showForm = false;
    public ?int $editingId = null;
    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('viewAny', Lead::class);

        if ($this->showForm) {
            Gate::authorize('create', Lead::class);
        }

        $this->resetForm();
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
        $lead->update(['status' => 'inactive']);
        $lead->delete();
        Flux::toast(variant: 'success', text: 'Lead desactivado.');
    }

    #[Computed]
    public function leads(): \Illuminate\Support\Collection
    {
        return Lead::query()
            ->visibleFor(Auth::user())
            ->with(['source', 'pipelineStatus', 'assignedUser'])
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->where('full_name', 'like', '%'.$this->search.'%')
                    ->orWhere('phone', 'like', '%'.$this->search.'%')
                    ->orWhere('email', 'like', '%'.$this->search.'%');
            }))
            ->when($this->source !== '', fn ($query) => $query->where('source_id', $this->source))
            ->when($this->stage !== '', fn ($query) => $query->where('status_id', $this->stage))
            ->when($this->district !== '', fn ($query) => $query->where('district', 'like', '%'.$this->district.'%'))
            ->latest('entered_at')
            ->limit(80)
            ->get();
    }

    #[Computed]
    public function sources(): \Illuminate\Support\Collection
    {
        return LeadSource::query()->where('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function statuses(): \Illuminate\Support\Collection
    {
        return LeadStatus::query()->where('status', 'active')->orderBy('sort_order')->get();
    }

    #[Computed]
    public function advisors(): \Illuminate\Support\Collection
    {
        $user = Auth::user();

        return User::query()
            ->where('status', 'active')
            ->when($user->isSupervisor(), fn ($query) => $query->where(function ($query) use ($user) {
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
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Leads</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Clientes potenciales, origen, etapa y asesor responsable.</p>
        </div>
        <div class="crm-actions">
            <flux:button icon="arrow-path" wire:click="$refresh">Actualizar</flux:button>
            @can('create', \App\Models\Lead::class)
                <flux:button icon="plus" variant="primary" wire:click="create">Nuevo lead</flux:button>
            @endcan
        </div>
    </header>

    <section class="crm-card">
        <div class="crm-toolbar">
            <flux:input wire:model.live.debounce.350ms="search" label="Buscar" placeholder="Nombre, telefono o correo" />
            <flux:select wire:model.live="source" label="Origen"><option value="">Todos</option>@foreach ($this->sources as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="stage" label="Etapa"><option value="">Todas</option>@foreach ($this->statuses as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</flux:select>
            <flux:input wire:model.live.debounce.350ms="district" label="Distrito" placeholder="Distrito" />
        </div>
    </section>

    @if ($showForm)
        <section class="crm-card crm-panel-pad">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div><p class="crm-eyebrow">{{ $editingId ? 'Edicion' : 'Registro' }}</p><h2 class="text-lg font-bold text-[var(--color-crm-navy)]">{{ $editingId ? 'Editar lead' : 'Nuevo lead' }}</h2></div>
                <flux:button icon="x-mark" wire:click="$set('showForm', false)">Cerrar</flux:button>
            </div>
            <form wire:submit="save" class="space-y-4">
                <div class="crm-form-grid">
                    <flux:input wire:model="form.first_name" label="Nombre" required />
                    <flux:input wire:model="form.last_name" label="Apellido" />
                    <flux:input wire:model="form.phone" label="Telefono" required />
                    <flux:input wire:model="form.email" label="Correo" type="email" />
                    <flux:select wire:model="form.source_id" label="Origen" required>@foreach ($this->sources as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</flux:select>
                    <flux:select wire:model="form.status_id" label="Etapa" required>@foreach ($this->statuses as $item)<option value="{{ $item->id }}">{{ $item->name }}</option>@endforeach</flux:select>
                    <flux:select wire:model="form.assigned_user_id" label="Asesor"><option value="">Sin asignar</option>@foreach ($this->advisors as $advisor)<option value="{{ $advisor->id }}">{{ $advisor->name }}</option>@endforeach</flux:select>
                    <flux:input wire:model="form.district" label="Distrito" />
                    <flux:input wire:model="form.property_type" label="Tipo de propiedad" />
                    <flux:input wire:model="form.budget_min" label="Presupuesto min." type="number" step="0.01" />
                    <flux:input wire:model="form.budget_max" label="Presupuesto max." type="number" step="0.01" />
                    <flux:select wire:model="form.budget_currency" label="Moneda"><option value="PEN">PEN</option><option value="USD">USD</option></flux:select>
                </div>
                <flux:textarea wire:model="form.notes" label="Notas" />
                <div class="crm-form-actions">
                    <flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary">Guardar</flux:button>
                </div>
            </form>
        </section>
    @endif

    <section class="crm-card crm-panel-pad">
        <div class="crm-table-wrap">
            <table class="crm-table">
                <thead><tr><th>Lead</th><th>Origen</th><th>Etapa</th><th>Zona</th><th>Presupuesto</th><th>Asesor</th><th>Acciones</th></tr></thead>
                <tbody>
                    @forelse ($this->leads as $lead)
                        <tr wire:key="lead-{{ $lead->id }}">
                            <td><a class="font-semibold text-[var(--color-crm-blue)]" href="{{ route('leads.show', $lead) }}" wire:navigate>{{ $lead->full_name }}</a><br><span class="text-xs text-[var(--color-crm-muted)]">{{ $lead->phone }} {{ $lead->email ? ' - '.$lead->email : '' }}</span></td>
                            <td>{{ $lead->source?->name }}</td>
                            <td><span class="crm-badge crm-badge--info">{{ $lead->pipelineStatus?->name }}</span></td>
                            <td>{{ $lead->district ?: '-' }}</td>
                            <td>{{ $lead->budgetDisplay() }}</td>
                            <td>{{ $lead->assignedUser?->name ?? 'Sin asignar' }}</td>
                            <td>
                                <div class="crm-actions">
                                    <flux:button size="sm" :href="route('leads.show', $lead)" icon="eye" wire:navigate>Ver</flux:button>
                                    @can('update', $lead)<flux:button size="sm" icon="pencil-square" wire:click="edit({{ $lead->id }})">Editar</flux:button>@endcan
                                    @can('delete', $lead)<flux:button size="sm" icon="archive-box" wire:click="disable({{ $lead->id }})" wire:confirm="Desactivar este lead?">Desactivar</flux:button>@endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="crm-empty">No hay leads con estos filtros.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</section>
