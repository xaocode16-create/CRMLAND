<?php

use App\Enums\FollowUpStatus;
use App\Enums\FollowUpType;
use App\Models\FollowUp;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Calendario')] class extends Component
{
    public string $from = '';
    public string $to = '';
    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('appointments.view');
        $this->from = now()->startOfWeek()->format('Y-m-d');
        $this->to = now()->endOfWeek()->format('Y-m-d');
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

    public function today(): void
    {
        $this->from = now()->toDateString();
        $this->to = now()->toDateString();
    }

    public function week(): void
    {
        $this->from = now()->startOfWeek()->format('Y-m-d');
        $this->to = now()->endOfWeek()->format('Y-m-d');
    }

    #[Computed]
    public function days(): \Illuminate\Support\Collection
    {
        return FollowUp::query()
            ->visibleFor(Auth::user())
            ->with(['lead.assignedUser', 'assignedUser'])
            ->whereBetween('scheduled_at', [
                $this->from.' 00:00:00',
                $this->to.' 23:59:59',
            ])
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn (FollowUp $followUp): string => $followUp->scheduled_at->format('Y-m-d'));
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Agenda</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Calendario</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Vista diaria y semanal de llamadas, visitas, reuniones y correos.</p>
        </div>
        <div class="crm-actions">
            <flux:button icon="calendar" wire:click="today">Hoy</flux:button>
            <flux:button icon="calendar-days" wire:click="week">Semana</flux:button>
            <flux:button :href="route('follow-ups.index')" icon="clipboard-document-list" wire:navigate>Seguimientos</flux:button>
        </div>
    </header>

    <section class="crm-card">
        <div class="crm-toolbar">
            <flux:input wire:model.live="from" label="Desde" type="date" />
            <flux:input wire:model.live="to" label="Hasta" type="date" />
            <flux:select wire:model.live="status" label="Estado">
                <option value="">Todos</option>
                @foreach (FollowUpStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </flux:select>
        </div>
    </section>

    <section class="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->days as $date => $items)
            <article class="crm-card crm-panel-pad" wire:key="day-{{ $date }}">
                <div class="mb-4 flex items-center justify-between gap-3">
                    <div>
                        <p class="crm-eyebrow">{{ \Illuminate\Support\Carbon::parse($date)->format('d/m/Y') }}</p>
                        <h2 class="text-lg font-bold text-[var(--color-crm-navy)]">{{ $items->count() }} actividad(es)</h2>
                    </div>
                    <span class="crm-badge crm-badge--info">{{ \Illuminate\Support\Carbon::parse($date)->locale('es')->dayName }}</span>
                </div>

                <div class="space-y-3">
                    @foreach ($items as $item)
                        <article class="rounded-lg border border-[var(--color-crm-border)] bg-white p-3" wire:key="calendar-item-{{ $item->id }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <strong>{{ $item->scheduled_at?->format('H:i') }} - {{ FollowUpType::tryFrom($item->type)?->label() ?? $item->type }}</strong>
                                    <p class="text-sm text-[var(--color-crm-muted)]">{{ $item->assignedUser?->name ?? 'Sin asesor' }}</p>
                                </div>
                                <span class="crm-badge {{ $item->status === 'COMPLETED' ? 'crm-badge--success' : 'crm-badge--warning' }}">{{ $item->statusLabel() }}</span>
                            </div>
                            <a class="mt-2 block font-semibold text-[var(--color-crm-blue)]" href="{{ route('leads.show', $item->lead) }}" wire:navigate>{{ $item->lead?->full_name }}</a>
                            @if ($item->note)
                                <p class="mt-2 text-sm text-[var(--color-crm-muted)]">{{ $item->note }}</p>
                            @endif
                            @if ($item->status === FollowUpStatus::Pending->value)
                                <flux:button size="sm" class="mt-3" wire:click="complete({{ $item->id }})">Completar</flux:button>
                            @endif
                        </article>
                    @endforeach
                </div>
            </article>
        @empty
            <section class="crm-card crm-empty">No hay actividades en el rango seleccionado.</section>
        @endforelse
    </section>
</section>
