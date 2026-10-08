<?php

use App\Enums\SaleStatus;
use App\Models\Commission;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Property;
use App\Models\Sale;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reportes')] class extends Component
{
    public string $from = '';
    public string $to = '';

    public function mount(): void
    {
        Gate::authorize('reports.view');
        $this->from = now()->startOfMonth()->format('Y-m-d');
        $this->to = now()->format('Y-m-d');
    }

    #[Computed]
    public function stats(): array
    {
        $from = $this->from.' 00:00:00';
        $to = $this->to.' 23:59:59';
        $user = Auth::user();

        return [
            'leads' => Lead::query()->visibleFor($user)->whereBetween('entered_at', [$from, $to])->count(),
            'properties' => Property::query()->visibleFor($user)->count(),
            'followups_pending' => FollowUp::query()->visibleFor($user)->where('status', 'PENDING')->count(),
            'sales' => Sale::query()->visibleFor($user)->whereBetween('sold_at', [$from, $to])->whereNot('status', SaleStatus::Cancelled->value)->count(),
            'sales_amount' => Sale::query()->visibleFor($user)->whereBetween('sold_at', [$from, $to])->whereNot('status', SaleStatus::Cancelled->value)->sum('price'),
            'commissions_pending' => Commission::query()->visibleFor($user)->where('status', 'PENDING')->sum('amount'),
        ];
    }

    #[Computed]
    public function pipeline(): \Illuminate\Support\Collection
    {
        return LeadStatus::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->get()
            ->map(function (LeadStatus $status): array {
                return [
                    'name' => $status->name,
                    'color' => $status->color,
                    'count' => Lead::query()
                        ->visibleFor(Auth::user())
                        ->where('status_id', $status->id)
                        ->count(),
                ];
            });
    }

    #[Computed]
    public function sources(): \Illuminate\Support\Collection
    {
        return LeadSource::query()
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(function (LeadSource $source): array {
                return [
                    'name' => $source->name,
                    'count' => Lead::query()
                        ->visibleFor(Auth::user())
                        ->where('source_id', $source->id)
                        ->count(),
                ];
            })
            ->filter(fn (array $item): bool => $item['count'] > 0)
            ->values();
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Gestion</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Reportes</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Indicadores comerciales con aislamiento por rol y equipo.</p>
        </div>
        <div class="crm-actions">
            <flux:input wire:model.live="from" type="date" label="Desde" />
            <flux:input wire:model.live="to" type="date" label="Hasta" />
        </div>
    </header>

    <div class="crm-grid crm-grid--stats">
        <article class="crm-card crm-stat"><span>Leads nuevos</span><strong>{{ $this->stats['leads'] }}</strong></article>
        <article class="crm-card crm-stat"><span>Propiedades visibles</span><strong>{{ $this->stats['properties'] }}</strong></article>
        <article class="crm-card crm-stat"><span>Seguimientos pendientes</span><strong>{{ $this->stats['followups_pending'] }}</strong></article>
        <article class="crm-card crm-stat"><span>Ventas</span><strong>{{ $this->stats['sales'] }}</strong></article>
        <article class="crm-card crm-stat"><span>Monto vendido</span><strong>S/ {{ number_format((float) $this->stats['sales_amount'], 2) }}</strong></article>
        <article class="crm-card crm-stat"><span>Comisiones pendientes</span><strong>S/ {{ number_format((float) $this->stats['commissions_pending'], 2) }}</strong></article>
    </div>

    <div class="grid gap-4 xl:grid-cols-2">
        <section class="crm-card crm-panel-pad">
            <div class="mb-4">
                <p class="crm-eyebrow">Pipeline</p>
                <h2 class="text-lg font-bold text-[var(--color-crm-navy)]">Leads por etapa</h2>
            </div>
            <div class="space-y-3">
                @foreach ($this->pipeline as $item)
                    <div class="rounded-lg border border-[var(--color-crm-border)] bg-white p-3" wire:key="pipeline-report-{{ $item['name'] }}">
                        <div class="flex items-center justify-between gap-3">
                            <span class="font-semibold">{{ $item['name'] }}</span>
                            <strong>{{ $item['count'] }}</strong>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-[var(--color-crm-bg-soft)]">
                            <div class="h-full rounded-full" style="width: {{ min(100, $item['count'] * 8) }}%; background: {{ $item['color'] ?: '#008cff' }}"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="crm-card crm-panel-pad">
            <div class="mb-4">
                <p class="crm-eyebrow">Origenes</p>
                <h2 class="text-lg font-bold text-[var(--color-crm-navy)]">Leads por canal</h2>
            </div>
            <div class="space-y-3">
                @forelse ($this->sources as $source)
                    <div class="flex items-center justify-between rounded-lg border border-[var(--color-crm-border)] bg-white p-3" wire:key="source-report-{{ $source['name'] }}">
                        <span class="font-semibold">{{ $source['name'] }}</span>
                        <span class="crm-badge crm-badge--info">{{ $source['count'] }}</span>
                    </div>
                @empty
                    <div class="crm-empty">Sin datos por origen.</div>
                @endforelse
            </div>
        </section>
    </div>
</section>
