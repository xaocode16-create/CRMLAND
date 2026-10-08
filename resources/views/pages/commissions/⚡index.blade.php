<?php

use App\Enums\CommissionStatus;
use App\Models\Commission;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Comisiones')] class extends Component
{
    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Commission::class);
    }

    public function approve(int $commissionId): void
    {
        $commission = Commission::query()->findOrFail($commissionId);
        Gate::authorize('update', $commission);

        $commission->update([
            'status' => CommissionStatus::Approved->value,
            'approved_by_user_id' => Auth::id(),
            'approved_at' => now(),
        ]);

        Flux::toast(variant: 'success', text: 'Comision aprobada.');
    }

    public function pay(int $commissionId): void
    {
        $commission = Commission::query()->findOrFail($commissionId);
        Gate::authorize('update', $commission);

        $commission->update([
            'status' => CommissionStatus::Paid->value,
            'paid_at' => now(),
        ]);

        Flux::toast(variant: 'success', text: 'Comision marcada como pagada.');
    }

    public function cancel(int $commissionId): void
    {
        $commission = Commission::query()->findOrFail($commissionId);
        Gate::authorize('update', $commission);

        $commission->update(['status' => CommissionStatus::Cancelled->value]);
        Flux::toast(variant: 'success', text: 'Comision cancelada.');
    }

    #[Computed]
    public function commissions(): \Illuminate\Support\Collection
    {
        return Commission::query()
            ->visibleFor(Auth::user())
            ->with(['sale.lead', 'sale.property', 'advisor'])
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->latest()
            ->limit(150)
            ->get();
    }

    #[Computed]
    public function totals(): array
    {
        $commissions = $this->commissions;

        return [
            'pending' => $commissions->where('status', CommissionStatus::Pending->value)->sum('amount'),
            'approved' => $commissions->where('status', CommissionStatus::Approved->value)->sum('amount'),
            'paid' => $commissions->where('status', CommissionStatus::Paid->value)->sum('amount'),
        ];
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Operaciones</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Comisiones</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Control de comisiones pendientes, aprobadas y pagadas.</p>
        </div>
        <div class="crm-actions">
            <flux:select wire:model.live="status" label="Estado">
                <option value="">Todas</option>
                @foreach (CommissionStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </flux:select>
        </div>
    </header>

    <div class="crm-grid crm-grid--stats">
        <article class="crm-card crm-stat"><span>Pendientes</span><strong>S/ {{ number_format((float) $this->totals['pending'], 2) }}</strong></article>
        <article class="crm-card crm-stat"><span>Aprobadas</span><strong>S/ {{ number_format((float) $this->totals['approved'], 2) }}</strong></article>
        <article class="crm-card crm-stat"><span>Pagadas</span><strong>S/ {{ number_format((float) $this->totals['paid'], 2) }}</strong></article>
    </div>

    <section class="crm-card crm-panel-pad">
        <div class="crm-table-wrap">
            <table class="crm-table">
                <thead><tr><th>Venta</th><th>Asesor</th><th>%</th><th>Monto</th><th>Estado</th><th>Fechas</th><th>Acciones</th></tr></thead>
                <tbody>
                    @forelse ($this->commissions as $commission)
                        <tr wire:key="commission-{{ $commission->id }}">
                            <td>
                                {{ $commission->sale?->lead?->full_name ?? 'Venta #'.$commission->sale_id }}<br>
                                <span class="text-xs text-[var(--color-crm-muted)]">{{ $commission->sale?->property?->title ?? 'Sin propiedad' }}</span>
                            </td>
                            <td>{{ $commission->advisor?->name ?? '-' }}</td>
                            <td>{{ number_format((float) $commission->percentage, 3) }}%</td>
                            <td>{{ $commission->amountDisplay() }}</td>
                            <td><span class="crm-badge {{ $commission->status === 'PAID' ? 'crm-badge--success' : ($commission->status === 'CANCELLED' ? 'crm-badge--danger' : 'crm-badge--warning') }}">{{ $commission->statusLabel() }}</span></td>
                            <td>
                                <span class="block text-xs">Aprobada: {{ $commission->approved_at?->format('d/m/Y') ?? '-' }}</span>
                                <span class="block text-xs">Pagada: {{ $commission->paid_at?->format('d/m/Y') ?? '-' }}</span>
                            </td>
                            <td>
                                <div class="crm-actions">
                                    @if ($commission->status === CommissionStatus::Pending->value)
                                        <flux:button size="sm" wire:click="approve({{ $commission->id }})">Aprobar</flux:button>
                                    @endif
                                    @if ($commission->status === CommissionStatus::Approved->value)
                                        <flux:button size="sm" wire:click="pay({{ $commission->id }})">Pagar</flux:button>
                                    @endif
                                    @if ($commission->status !== CommissionStatus::Cancelled->value && $commission->status !== CommissionStatus::Paid->value)
                                        <flux:button size="sm" wire:click="cancel({{ $commission->id }})" wire:confirm="Cancelar esta comision?">Cancelar</flux:button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="crm-empty">No hay comisiones registradas.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</section>
