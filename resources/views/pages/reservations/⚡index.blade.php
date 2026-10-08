<?php

use App\Actions\Reservations\CreateReservationAction;
use App\Enums\PropertyStatus;
use App\Enums\ReservationStatus;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Reservation;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reservas')] class extends Component
{
    public string $status = '';
    public bool $showForm = false;
    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('viewAny', Reservation::class);
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->form = [
            'lead_id' => null,
            'property_id' => null,
            'expires_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
        ];
    }

    public function create(): void
    {
        Gate::authorize('create', Reservation::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function save(CreateReservationAction $createReservation): void
    {
        Gate::authorize('create', Reservation::class);

        $validated = $this->validate([
            'form.lead_id' => ['required', 'integer', 'exists:leads,id'],
            'form.property_id' => ['required', 'integer', 'exists:properties,id'],
            'form.expires_at' => ['required', 'date', 'after:now'],
        ])['form'];

        $lead = Lead::query()->findOrFail((int) $validated['lead_id']);
        $property = Property::query()->findOrFail((int) $validated['property_id']);
        Gate::authorize('view', $lead);
        Gate::authorize('view', $property);

        try {
            $createReservation->handle([
                'lead_id' => (int) $validated['lead_id'],
                'property_id' => (int) $validated['property_id'],
                'expires_at' => $validated['expires_at'],
            ], Auth::user());
        } catch (\RuntimeException $exception) {
            $this->addError('form.property_id', $exception->getMessage());

            return;
        }

        $this->showForm = false;
        $this->resetForm();
        Flux::toast(variant: 'success', text: 'Reserva creada.');
    }

    public function cancel(int $reservationId): void
    {
        $reservation = Reservation::query()->with('property')->findOrFail($reservationId);
        Gate::authorize('update', $reservation);

        DB::transaction(function () use ($reservation): void {
            $reservation->update(['status' => ReservationStatus::Cancelled->value]);

            $hasActiveReservation = Reservation::query()
                ->where('property_id', $reservation->property_id)
                ->where('status', ReservationStatus::Active->value)
                ->whereKeyNot($reservation->id)
                ->exists();

            if (! $hasActiveReservation && $reservation->property?->status === PropertyStatus::Reserved->value) {
                $reservation->property->update(['status' => PropertyStatus::Available->value]);
            }
        });

        Flux::toast(variant: 'success', text: 'Reserva cancelada.');
    }

    #[Computed]
    public function reservations(): \Illuminate\Support\Collection
    {
        return Reservation::query()
            ->visibleFor(Auth::user())
            ->with(['lead.assignedUser', 'property.type', 'user'])
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->latest('reserved_at')
            ->limit(120)
            ->get();
    }

    #[Computed]
    public function leads(): \Illuminate\Support\Collection
    {
        return Lead::query()
            ->visibleFor(Auth::user())
            ->orderBy('full_name')
            ->limit(100)
            ->get(['id', 'full_name', 'phone', 'assigned_user_id']);
    }

    #[Computed]
    public function properties(): \Illuminate\Support\Collection
    {
        return Property::query()
            ->visibleFor(Auth::user())
            ->where('status', PropertyStatus::Available->value)
            ->orderBy('title')
            ->limit(100)
            ->get(['id', 'code', 'title', 'district', 'price', 'currency', 'assigned_user_id']);
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Operaciones</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Reservas</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Bloqueo temporal de propiedades para leads calificados.</p>
        </div>
        <div class="crm-actions">
            <flux:select wire:model.live="status" label="Estado">
                <option value="">Todas</option>
                @foreach (ReservationStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </flux:select>
            @can('create', \App\Models\Reservation::class)
                <flux:button icon="plus" variant="primary" wire:click="create">Nueva reserva</flux:button>
            @endcan
        </div>
    </header>

    @if ($showForm)
        <section class="crm-card crm-panel-pad">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div><p class="crm-eyebrow">Operacion</p><h2 class="text-lg font-bold text-[var(--color-crm-navy)]">Nueva reserva</h2></div>
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
                    <flux:select wire:model="form.property_id" label="Propiedad" required>
                        <option value="">Seleccionar</option>
                        @foreach ($this->properties as $property)
                            <option value="{{ $property->id }}">{{ $property->code }} - {{ $property->title }} / {{ $property->district }}</option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="form.expires_at" type="datetime-local" label="Expira" required />
                </div>
                <div class="crm-form-actions">
                    <flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary">Reservar</flux:button>
                </div>
            </form>
        </section>
    @endif

    <section class="crm-card crm-panel-pad">
        <div class="crm-table-wrap">
            <table class="crm-table">
                <thead><tr><th>Reserva</th><th>Lead</th><th>Propiedad</th><th>Asesor</th><th>Expira</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                    @forelse ($this->reservations as $reservation)
                        <tr wire:key="reservation-{{ $reservation->id }}">
                            <td>{{ $reservation->reserved_at?->format('d/m/Y H:i') }}</td>
                            <td><a class="font-semibold text-[var(--color-crm-blue)]" href="{{ route('leads.show', $reservation->lead) }}" wire:navigate>{{ $reservation->lead?->full_name }}</a></td>
                            <td><a class="font-semibold text-[var(--color-crm-blue)]" href="{{ route('properties.show', $reservation->property) }}" wire:navigate>{{ $reservation->property?->title }}</a><br><span class="text-xs text-[var(--color-crm-muted)]">{{ $reservation->property?->district }}</span></td>
                            <td>{{ $reservation->user?->name }}</td>
                            <td>{{ $reservation->expires_at?->format('d/m/Y H:i') }}</td>
                            <td><span class="crm-badge {{ $reservation->status === 'ACTIVE' ? 'crm-badge--warning' : ($reservation->status === 'COMPLETED' ? 'crm-badge--success' : 'crm-badge--danger') }}">{{ $reservation->statusLabel() }}</span></td>
                            <td>
                                @if ($reservation->status === ReservationStatus::Active->value)
                                    <flux:button size="sm" wire:click="cancel({{ $reservation->id }})" wire:confirm="Cancelar esta reserva?">Cancelar</flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="crm-empty">No hay reservas registradas.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</section>
