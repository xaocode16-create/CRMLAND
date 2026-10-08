<?php

use App\Models\Commission;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Sale;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Panel')] class extends Component
{
    #[Computed]
    public function stats(): array
    {
        $user = Auth::user();

        return [
            'leads' => Lead::query()->visibleFor($user)->count(),
            'propiedades' => Property::query()->visibleFor($user)->count(),
            'seguimientos' => FollowUp::query()
                ->visibleFor($user)
                ->where('status', 'PENDING')
                ->whereDate('scheduled_at', today())
                ->count(),
            'ventas' => Sale::query()->visibleFor($user)->whereNot('status', 'CANCELLED')->count(),
            'comisiones' => Commission::query()->visibleFor($user)->where('status', 'PENDING')->sum('amount'),
        ];
    }

    #[Computed]
    public function recentLeads(): \Illuminate\Support\Collection
    {
        return Lead::query()
            ->visibleFor(Auth::user())
            ->with(['source', 'pipelineStatus', 'assignedUser'])
            ->latest('entered_at')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function todayFollowUps(): \Illuminate\Support\Collection
    {
        return FollowUp::query()
            ->visibleFor(Auth::user())
            ->with(['lead:id,full_name,phone', 'assignedUser:id,name'])
            ->whereDate('scheduled_at', today())
            ->orderBy('scheduled_at')
            ->limit(8)
            ->get();
    }
};
?>

<section class="crm-page crm-dashboard-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Resumen comercial</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Panel</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Actividad actual del equipo y oportunidades recientes.</p>
        </div>
        <div class="crm-actions">
            <flux:button :href="route('leads.index')" icon="users" wire:navigate>Leads</flux:button>
            <flux:button :href="route('properties.index')" icon="building-office" variant="primary" wire:navigate>Propiedades</flux:button>
        </div>
    </header>

    <div class="crm-grid crm-grid--stats">
        <article class="crm-card crm-stat"><span>Leads activos</span><strong>{{ $this->stats['leads'] }}</strong></article>
        <article class="crm-card crm-stat"><span>Propiedades</span><strong>{{ $this->stats['propiedades'] }}</strong></article>
        <article class="crm-card crm-stat"><span>Seguimientos hoy</span><strong>{{ $this->stats['seguimientos'] }}</strong></article>
        <article class="crm-card crm-stat"><span>Ventas</span><strong>{{ $this->stats['ventas'] }}</strong></article>
        <article class="crm-card crm-stat"><span>Comisiones pendientes</span><strong>S/ {{ number_format((float) $this->stats['comisiones'], 2) }}</strong></article>
    </div>

    <div class="grid gap-4 xl:grid-cols-[1.4fr_1fr]">
        <section class="crm-card crm-panel-pad">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <p class="crm-eyebrow">Pipeline</p>
                    <h2 class="text-lg font-bold text-[var(--color-crm-navy)]">Ultimos leads</h2>
                </div>
                <flux:button :href="route('leads.index')" size="sm" icon="arrow-right" wire:navigate>Ver todos</flux:button>
            </div>
            <div class="crm-table-wrap">
                <table class="crm-table">
                    <thead><tr><th>Lead</th><th>Origen</th><th>Etapa</th><th>Asesor</th></tr></thead>
                    <tbody>
                        @forelse ($this->recentLeads as $lead)
                            <tr>
                                <td><a class="font-semibold text-[var(--color-crm-blue)]" href="{{ route('leads.show', $lead) }}" wire:navigate>{{ $lead->full_name }}</a><br><span class="text-xs text-[var(--color-crm-muted)]">{{ $lead->phone }}</span></td>
                                <td>{{ $lead->source?->name ?? '-' }}</td>
                                <td><span class="crm-badge crm-badge--info">{{ $lead->pipelineStatus?->name ?? '-' }}</span></td>
                                <td>{{ $lead->assignedUser?->name ?? 'Sin asignar' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4"><div class="crm-empty">Todavia no hay leads.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="crm-card crm-panel-pad">
            <div class="mb-4">
                <p class="crm-eyebrow">Agenda</p>
                <h2 class="text-lg font-bold text-[var(--color-crm-navy)]">Seguimientos de hoy</h2>
            </div>
            <div class="space-y-3">
                @forelse ($this->todayFollowUps as $followUp)
                    <article class="rounded-lg border border-[var(--color-crm-border)] bg-white p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <strong>{{ $followUp->lead?->full_name }}</strong>
                                <p class="text-sm text-[var(--color-crm-muted)]">{{ $followUp->type }} - {{ $followUp->scheduled_at->format('H:i') }}</p>
                            </div>
                            <span class="crm-badge crm-badge--warning">{{ $followUp->priorityLabel() }}</span>
                        </div>
                    </article>
                @empty
                    <div class="crm-empty">Sin seguimientos programados para hoy.</div>
                @endforelse
            </div>
        </section>
    </div>
</section>
