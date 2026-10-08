<?php

use App\Actions\Leads\UpdateLeadStageAction;
use App\Models\Lead;
use App\Models\LeadStatus;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pipeline')] class extends Component
{
    public string $search = '';

    public function mount(): void
    {
        Gate::authorize('pipeline.view');
    }

    public function moveLead(int $leadId, int $statusId, UpdateLeadStageAction $updateLeadStage): void
    {
        $lead = Lead::query()->findOrFail($leadId);
        Gate::authorize('update', $lead);
        Gate::authorize('pipeline.edit');

        $updateLeadStage->handle($lead, $statusId, Auth::user());
        Flux::toast(variant: 'success', text: 'Etapa actualizada.');
    }

    #[Computed]
    public function statuses(): \Illuminate\Support\Collection
    {
        return LeadStatus::query()->where('status', 'active')->orderBy('sort_order')->get();
    }

    #[Computed]
    public function leads(): \Illuminate\Support\Collection
    {
        return Lead::query()
            ->visibleFor(Auth::user())
            ->with(['pipelineStatus', 'assignedUser', 'properties'])
            ->when($this->search !== '', fn ($query) => $query->where('full_name', 'like', '%'.$this->search.'%'))
            ->latest('entered_at')
            ->limit(200)
            ->get()
            ->groupBy('status_id');
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Comercial</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Pipeline</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Gestion visual por etapas del proceso comercial.</p>
        </div>
        <div class="crm-actions">
            <flux:input wire:model.live.debounce.350ms="search" placeholder="Buscar lead" />
            <flux:button :href="route('leads.index')" icon="users" wire:navigate>Leads</flux:button>
        </div>
    </header>

    <div class="crm-kanban">
        @foreach ($this->statuses as $status)
            @php($stageLeads = $this->leads->get($status->id, collect()))
            <section class="crm-kanban-column" wire:key="stage-{{ $status->id }}">
                <header>
                    <div>
                        <strong>{{ $status->name }}</strong>
                        <p class="text-xs text-[var(--color-crm-muted)]">{{ $stageLeads->count() }} lead(s)</p>
                    </div>
                    <span class="size-3 rounded-full" style="background: {{ $status->color ?: '#008cff' }}"></span>
                </header>
                <div>
                    @forelse ($stageLeads as $lead)
                        <article class="crm-kanban-card" wire:key="pipeline-lead-{{ $lead->id }}">
                            <a class="font-semibold text-[var(--color-crm-blue)]" href="{{ route('leads.show', $lead) }}" wire:navigate>{{ $lead->full_name }}</a>
                            <p class="text-sm text-[var(--color-crm-muted)]">{{ $lead->phone }} - {{ $lead->district ?: 'Sin distrito' }}</p>
                            <div class="mt-3 flex items-center justify-between gap-2">
                                <span class="crm-badge">{{ $lead->assignedUser?->name ?? 'Sin asesor' }}</span>
                                <flux:dropdown>
                                    <flux:button size="sm" icon="arrows-right-left">Mover</flux:button>
                                    <flux:menu>
                                        @foreach ($this->statuses as $target)
                                            @if ($target->id !== $status->id)
                                                <flux:menu.item wire:click="moveLead({{ $lead->id }}, {{ $target->id }})">{{ $target->name }}</flux:menu.item>
                                            @endif
                                        @endforeach
                                    </flux:menu>
                                </flux:dropdown>
                            </div>
                        </article>
                    @empty
                        <div class="crm-empty">Sin leads.</div>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</section>
