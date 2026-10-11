<?php

use App\Actions\Reservations\CreateReservationAction;
use App\Enums\PropertyOperation;
use App\Enums\PropertyStatus;
use App\Enums\ReservationApprovalStatus;
use App\Enums\ReservationStatus;
use App\Models\Lead;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\Reservation;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Reservas')] class extends Component
{
    use WithFileUploads;

    public string $search = '';
    public string $status = '';
    public string $approvalStatus = '';
    public string $advisor = '';
    public string $propertyType = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public bool $showFilters = false;
    public bool $showForm = false;
    public bool $showAllActivity = false;
    public array $form = [];
    public array $documents = [];

    public function mount(): void
    {
        Gate::authorize('viewAny', Reservation::class);
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $user = Auth::user();

        $this->form = [
            'lead_id' => null,
            'property_id' => null,
            'advisor_user_id' => $user->isAsesor() ? $user->id : null,
            'supervisor_user_id' => $user->supervisor_user_id,
            'reserved_at' => now()->format('Y-m-d\\TH:i'),
            'expires_at' => now()->addDays(7)->format('Y-m-d\\TH:i'),
            'operation_type' => PropertyOperation::Sale->value,
            'channel' => 'PORTAL_WEB',
            'separation_amount' => 0,
            'currency' => 'PEN',
            'branch' => '',
            'priority' => 'NORMAL',
            'requires_approval' => true,
            'reservation_reason' => '',
            'payment_method' => '',
            'payment_bank' => '',
            'payment_operation' => '',
            'payment_date' => now()->format('Y-m-d\\TH:i'),
            'amount_paid' => 0,
            'payment_status' => 'PENDING',
            'payment_observation' => '',
        ];
        $this->documents = [];
    }

    public function create(): void
    {
        Gate::authorize('create', Reservation::class);
        $this->resetForm();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function toggleFilters(): void
    {
        $this->showFilters = ! $this->showFilters;
    }

    public function updatedFormPropertyId(mixed $value): void
    {
        $property = Property::query()->find((int) $value);

        if ($property instanceof Property) {
            $this->form['operation_type'] = $property->operation_type;
            $this->form['currency'] = $property->currency ?: 'PEN';
        }

        $this->resetValidation('form.property_id');
    }

    public function save(CreateReservationAction $createReservation): void
    {
        Gate::authorize('create', Reservation::class);

        $validated = $this->validate([
            'form.lead_id' => ['required', 'integer', 'exists:leads,id'],
            'form.property_id' => ['required', 'integer', 'exists:properties,id'],
            'form.advisor_user_id' => ['required', 'integer', 'exists:users,id'],
            'form.supervisor_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'form.reserved_at' => ['required', 'date'],
            'form.expires_at' => ['required', 'date', 'after:now', 'after:form.reserved_at'],
            'form.operation_type' => ['required', Rule::in(array_column(PropertyOperation::cases(), 'value'))],
            'form.channel' => ['required', 'string', 'max:60'],
            'form.separation_amount' => ['nullable', 'numeric', 'min:0'],
            'form.currency' => ['required', Rule::in(['PEN', 'USD'])],
            'form.branch' => ['nullable', 'string', 'max:120'],
            'form.priority' => ['required', Rule::in(['LOW', 'NORMAL', 'HIGH'])],
            'form.requires_approval' => ['boolean'],
            'form.reservation_reason' => ['nullable', 'string', 'max:2000'],
            'form.payment_method' => ['nullable', 'string', 'max:80'],
            'form.payment_bank' => ['nullable', 'string', 'max:120'],
            'form.payment_operation' => ['nullable', 'string', 'max:120'],
            'form.payment_date' => ['nullable', 'date'],
            'form.amount_paid' => ['nullable', 'numeric', 'min:0'],
            'form.payment_status' => ['required', Rule::in(['PENDING', 'CONFIRMED', 'PARTIAL'])],
            'form.payment_observation' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array', 'max:8'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:10240'],
        ])['form'];

        $lead = Lead::query()->findOrFail((int) $validated['lead_id']);
        $property = Property::query()->findOrFail((int) $validated['property_id']);
        Gate::authorize('view', $lead);
        Gate::authorize('view', $property);
        abort_unless(Auth::user()->canAssignTo((int) $validated['advisor_user_id']), 403);

        try {
            $reservation = $createReservation->handle([
                ...$validated,
                'lead_id' => (int) $validated['lead_id'],
                'property_id' => (int) $validated['property_id'],
                'advisor_user_id' => (int) $validated['advisor_user_id'],
                'supervisor_user_id' => $validated['supervisor_user_id'] ? (int) $validated['supervisor_user_id'] : null,
                'documents_json' => null,
            ], Auth::user());
        } catch (\RuntimeException $exception) {
            $this->addError('form.property_id', $exception->getMessage());

            return;
        }

        if ($this->documents !== []) {
            $reservation->update(['documents_json' => $this->storeDocuments($reservation)]);
        }

        $this->closeForm();
        $this->resetForm();
        Flux::toast(variant: 'success', text: 'Reserva creada correctamente.');
    }

    public function approve(int $reservationId): void
    {
        $reservation = Reservation::query()->findOrFail($reservationId);
        Gate::authorize('approve', $reservation);

        $reservation->update([
            'approval_status' => ReservationApprovalStatus::Approved->value,
            'approved_by_user_id' => Auth::id(),
            'approved_at' => now(),
        ]);

        Flux::toast(variant: 'success', text: 'Reserva aprobada.');
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

    public function clearFilters(): void
    {
        $this->reset('search', 'status', 'approvalStatus', 'advisor', 'propertyType', 'dateFrom', 'dateTo');
    }

    public function showPendingApprovals(): void
    {
        $this->status = ReservationStatus::Active->value;
        $this->approvalStatus = ReservationApprovalStatus::Pending->value;
        $this->showFilters = true;
    }

    public function toggleAllActivity(): void
    {
        $this->showAllActivity = ! $this->showAllActivity;
    }

    public function canApproveReservations(): bool
    {
        return Auth::user()->isAdministrador() || Auth::user()->isSupervisor();
    }

    #[Computed]
    public function reservations(): \Illuminate\Support\Collection
    {
        return $this->reservationQuery()
            ->with(['lead', 'property.type', 'property.images', 'user', 'supervisor'])
            ->latest('reserved_at')
            ->limit(120)
            ->get();
    }

    #[Computed]
    public function reservationMetrics(): array
    {
        $query = Reservation::query()->visibleFor(Auth::user());

        return [
            'active' => (clone $query)->where('status', ReservationStatus::Active->value)->count(),
            'pending' => (clone $query)->where('status', ReservationStatus::Active->value)
                ->where('approval_status', ReservationApprovalStatus::Pending->value)->count(),
            'expiring' => (clone $query)->where('status', ReservationStatus::Active->value)
                ->whereBetween('expires_at', [now(), now()->endOfDay()])->count(),
            'converted' => (clone $query)->where('status', ReservationStatus::Completed->value)->count(),
        ];
    }

    #[Computed]
    public function upcomingExpirations(): \Illuminate\Support\Collection
    {
        return Reservation::query()
            ->visibleFor(Auth::user())
            ->where('status', ReservationStatus::Active->value)
            ->whereBetween('expires_at', [now(), now()->addDays(7)->endOfDay()])
            ->with(['property.type', 'property.images'])
            ->orderBy('expires_at')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function recentReservations(): \Illuminate\Support\Collection
    {
        return Reservation::query()
            ->visibleFor(Auth::user())
            ->with('user')
            ->latest()
            ->limit($this->showAllActivity ? 30 : 5)
            ->get();
    }

    #[Computed]
    public function leads(): \Illuminate\Support\Collection
    {
        return Lead::query()
            ->visibleFor(Auth::user())
            ->orderBy('full_name')
            ->limit(150)
            ->get(['id', 'full_name', 'phone', 'email', 'assigned_user_id']);
    }

    #[Computed]
    public function properties(): \Illuminate\Support\Collection
    {
        return Property::query()
            ->visibleFor(Auth::user())
            ->where('status', PropertyStatus::Available->value)
            ->with('type')
            ->orderBy('title')
            ->limit(150)
            ->get(['id', 'code', 'title', 'district', 'address', 'price', 'currency', 'operation_type', 'property_type_id', 'assigned_user_id']);
    }

    #[Computed]
    public function selectedLead(): ?Lead
    {
        $leadId = $this->form['lead_id'] ?? null;

        return $leadId ? Lead::query()->find((int) $leadId) : null;
    }

    #[Computed]
    public function selectedProperty(): ?Property
    {
        $propertyId = $this->form['property_id'] ?? null;

        return $propertyId
            ? Property::query()->with(['type', 'images'])->find((int) $propertyId)
            : null;
    }

    #[Computed]
    public function propertyTypes(): \Illuminate\Support\Collection
    {
        return PropertyType::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug']);
    }

    #[Computed]
    public function advisors(): \Illuminate\Support\Collection
    {
        $user = Auth::user();

        return User::query()
            ->where('status', 'active')
            ->when($user->isSupervisor(), fn (Builder $query) => $query->where(function (Builder $query) use ($user): void {
                $query->whereKey($user->id)->orWhere('supervisor_user_id', $user->id);
            }))
            ->when($user->isAsesor(), fn (Builder $query) => $query->whereKey($user->id))
            ->orderBy('name')
            ->get(['id', 'name', 'supervisor_user_id']);
    }

    #[Computed]
    public function supervisors(): \Illuminate\Support\Collection
    {
        $user = Auth::user();

        return User::query()
            ->where('status', 'active')
            ->when($user->isAdministrador(), fn (Builder $query) => $query->whereHas('role', fn (Builder $query) => $query->where('slug', 'supervisor')))
            ->when(! $user->isAdministrador(), fn (Builder $query) => $query->whereKey($user->supervisor_user_id ?: $user->id))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function reservationQuery(): Builder
    {
        $query = Reservation::query()->visibleFor(Auth::user());

        return $query
            ->when($this->search !== '', function (Builder $query): void {
                $search = '%'.$this->search.'%';
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('code', 'like', $search)
                        ->orWhereHas('lead', fn (Builder $query) => $query->where('full_name', 'like', $search)->orWhere('phone', 'like', $search))
                        ->orWhereHas('property', fn (Builder $query) => $query->where('title', 'like', $search)->orWhere('code', 'like', $search));
                });
            })
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->approvalStatus !== '', fn (Builder $query) => $query->where('approval_status', $this->approvalStatus))
            ->when($this->advisor !== '', fn (Builder $query) => $query->where('user_id', (int) $this->advisor))
            ->when($this->propertyType !== '', fn (Builder $query) => $query->whereHas('property.type', fn (Builder $query) => $query->where('slug', $this->propertyType)))
            ->when($this->dateFrom !== '', fn (Builder $query) => $query->whereDate('reserved_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn (Builder $query) => $query->whereDate('reserved_at', '<=', $this->dateTo));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function storeDocuments(Reservation $reservation): array
    {
        return collect($this->documents)
            ->map(function (mixed $document) use ($reservation): array {
                $path = $document->store('reservations/'.$reservation->id, 'public');

                return [
                    'file_name' => $document->getClientOriginalName(),
                    'storage_key' => $path,
                    'mime_type' => $document->getMimeType(),
                    'file_size' => $document->getSize(),
                ];
            })
            ->values()
            ->all();
    }
};
?>

<section class="crm-page crm-reservations-page" wire:keydown.escape.window="closeForm">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Operaciones</p>
            <h1 class="crm-module-title">Gestion de reservas</h1>
            <p class="crm-module-description">Supervisa, aprueba y controla los bloqueos temporales de propiedades.</p>
            <span class="crm-reservation-role">{{ Auth::user()->isAdministrador() ? 'Administrador · vista global' : (Auth::user()->isSupervisor() ? 'Supervisor · vista de equipo' : 'Asesor · vista personal') }}</span>
        </div>
        <div class="crm-actions">
            <flux:select wire:model.live="status" label="Estado">
                <option value="">Todos</option>
                @foreach (ReservationStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </flux:select>
            <flux:button icon="funnel" wire:click="toggleFilters">Filtros</flux:button>
            @can('create', Reservation::class)
                <flux:button icon="plus" variant="primary" wire:click="create">Nueva reserva</flux:button>
            @endcan
        </div>
    </header>

    <div class="crm-grid crm-grid--stats crm-reservation-stats">
        <button type="button" class="crm-card crm-stat crm-reservation-stat crm-reservation-stat--blue" wire:click="$set('status', '{{ ReservationStatus::Active->value }}')"><span>Reservas activas</span><strong>{{ $this->reservationMetrics['active'] }}</strong><small>Bloqueos vigentes</small></button>
        <button type="button" class="crm-card crm-stat crm-reservation-stat crm-reservation-stat--amber" wire:click="$set('approvalStatus', '{{ ReservationApprovalStatus::Pending->value }}')"><span>Pendientes de aprobacion</span><strong>{{ $this->reservationMetrics['pending'] }}</strong><small>Requieren revision</small></button>
        <button type="button" class="crm-card crm-stat crm-reservation-stat crm-reservation-stat--red"><span>Por vencer hoy</span><strong>{{ $this->reservationMetrics['expiring'] }}</strong><small>Atencion prioritaria</small></button>
        <button type="button" class="crm-card crm-stat crm-reservation-stat crm-reservation-stat--green" wire:click="$set('status', '{{ ReservationStatus::Completed->value }}')"><span>Reservas convertidas</span><strong>{{ $this->reservationMetrics['converted'] }}</strong><small>Pasaron a venta</small></button>
    </div>

    @if ($showFilters)
        <section class="crm-card crm-reservation-filters">
            <div class="crm-reservation-filters__header"><div><p class="crm-eyebrow">Busqueda avanzada</p><h2 class="crm-section-title">Filtrar reservas</h2></div><flux:button icon="arrow-path" size="sm" wire:click="clearFilters">Limpiar filtros</flux:button></div>
            <div class="crm-toolbar">
                <flux:input wire:model.live.debounce.350ms="search" label="Buscar" placeholder="Lead, propiedad o codigo" />
                <flux:select wire:model.live="approvalStatus" label="Aprobacion"><option value="">Todas</option>@foreach (ReservationApprovalStatus::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
                <flux:select wire:model.live="advisor" label="Asesor"><option value="">Todos</option>@foreach ($this->advisors as $advisor)<option value="{{ $advisor->id }}">{{ $advisor->name }}</option>@endforeach</flux:select>
                <flux:select wire:model.live="propertyType" label="Tipo de propiedad"><option value="">Todos</option>@foreach ($this->propertyTypes as $type)<option value="{{ $type->slug }}">{{ $type->name }}</option>@endforeach</flux:select>
                <flux:input wire:model.live="dateFrom" label="Desde" type="date" />
                <flux:input wire:model.live="dateTo" label="Hasta" type="date" />
            </div>
        </section>
    @endif

    <div class="crm-reservations-layout">
        <section class="crm-card crm-panel-pad">
            <div class="crm-section-heading crm-reservation-table-heading"><div><p class="crm-eyebrow">Control operativo</p><h2 class="crm-section-title">Reservas ({{ $this->reservations->count() }})</h2></div><span class="crm-muted-caption">Actualizado en tiempo real</span></div>
            <div class="crm-table-wrap">
                <table class="crm-table crm-reservation-table">
                    <thead><tr><th>Codigo</th><th>Propiedad</th><th>Lead</th><th>Asesor</th><th>Fecha de reserva</th><th>Expira</th><th>Estado</th><th>Monto</th><th>Acciones</th></tr></thead>
                    <tbody>
                        @forelse ($this->reservations as $reservation)
                            @php
                                $image = $reservation->property?->images?->first();
                                $statusClass = match ($reservation->status) {
                                    ReservationStatus::Active->value => 'crm-badge--success',
                                    ReservationStatus::Completed->value => 'crm-badge--info',
                                    ReservationStatus::Cancelled->value, ReservationStatus::Expired->value => 'crm-badge--danger',
                                    default => 'crm-badge--warning',
                                };
                            @endphp
                            <tr wire:key="reservation-{{ $reservation->id }}">
                                <td><span class="crm-link">{{ $reservation->codeLabel() }}</span></td>
                                <td><div class="crm-reservation-property-cell">@if ($image)<img src="{{ Storage::url($image->storage_key) }}" alt="" />@else<span class="crm-reservation-property-placeholder"><i class="bi bi-buildings"></i></span>@endif<div><a class="crm-link" href="{{ route('properties.show', $reservation->property) }}" wire:navigate>{{ $reservation->property?->title }}</a><span class="crm-muted-caption">{{ $reservation->property?->type?->name }} · {{ $reservation->property?->district }}</span></div></div></td>
                                <td><a class="crm-link" href="{{ route('leads.show', $reservation->lead) }}" wire:navigate>{{ $reservation->lead?->full_name }}</a><span class="crm-muted-caption">{{ $reservation->lead?->phone }}</span></td>
                                <td>{{ $reservation->user?->name ?? '-' }}</td>
                                <td>{{ $reservation->reserved_at?->format('d/m/Y') }}<span class="crm-muted-caption">{{ $reservation->reserved_at?->format('H:i') }}</span></td>
                                <td class="{{ $reservation->expires_at?->isToday() ? 'crm-reservation-expiring' : '' }}">{{ $reservation->expires_at?->format('d/m/Y') }}<span class="crm-muted-caption">{{ $reservation->expires_at?->format('H:i') }}</span></td>
                                <td><span class="crm-badge {{ $statusClass }}">{{ $reservation->statusLabel() }}</span>@if ($reservation->approval_status === ReservationApprovalStatus::Pending->value)<span class="crm-badge crm-badge--warning">Pendiente</span>@endif</td>
                                <td>{{ $reservation->currency }} {{ number_format((float) $reservation->separation_amount, 2) }}</td>
                                <td><div class="crm-actions crm-reservation-row-actions">@if ($reservation->approval_status === ReservationApprovalStatus::Pending->value && $this->canApproveReservations())<flux:button size="sm" variant="primary" wire:click="approve({{ $reservation->id }})">Aprobar</flux:button>@endif @if ($reservation->status === ReservationStatus::Active->value)<flux:button size="sm" wire:click="cancel({{ $reservation->id }})" wire:confirm="Cancelar esta reserva?">Cancelar</flux:button>@endif</div></td>
                            </tr>
                        @empty
                            <tr><td colspan="9"><div class="crm-empty">No hay reservas que coincidan con los filtros.</div></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="crm-reservation-sidebar">
            <section class="crm-card crm-reservation-side-card"><div class="crm-reservation-side-card__heading"><h2>Proximos vencimientos</h2><span class="crm-link">7 dias</span></div><div class="crm-reservation-side-list">@forelse ($this->upcomingExpirations as $reservation)<div class="crm-reservation-side-item"><span class="crm-reservation-side-dot"></span><div><strong>{{ $reservation->property?->title }}</strong><span>{{ $reservation->property?->type?->name }} · {{ $reservation->property?->district }}</span></div><time>{{ $reservation->expires_at?->isToday() ? 'Hoy' : $reservation->expires_at?->diffForHumans() }}</time></div>@empty<p class="crm-muted-caption">No hay vencimientos proximos.</p>@endforelse</div></section>
            @if (Auth::user()->isAdministrador())
                <section class="crm-card crm-reservation-side-card"><div class="crm-reservation-side-card__heading"><h2>Alertas administrativas</h2><button type="button" class="crm-reservation-side-action" wire:click="showPendingApprovals">Ver todo</button></div><div class="crm-reservation-alert"><i class="bi bi-exclamation-circle"></i><div><strong>{{ $this->reservationMetrics['pending'] }} reservas pendientes</strong><span>Esperan aprobacion</span></div></div><div class="crm-reservation-alert"><i class="bi bi-clock-history"></i><div><strong>{{ $this->reservationMetrics['expiring'] }} por vencer hoy</strong><span>Requieren seguimiento</span></div></div></section>
            @else
                <section class="crm-card crm-reservation-side-card crm-reservation-role-card"><p class="crm-eyebrow">Alcance de tu rol</p><h2>{{ Auth::user()->isSupervisor() ? 'Equipo asignado' : 'Reservas propias' }}</h2><p>{{ Auth::user()->isSupervisor() ? 'Puedes consultar y aprobar reservas de los asesores de tu equipo.' : 'Puedes registrar y gestionar tus reservas. Las aprobaciones corresponden al supervisor o administrador.' }}</p></section>
            @endif
            <section class="crm-card crm-reservation-side-card"><div class="crm-reservation-side-card__heading"><h2>Actividad reciente</h2><button type="button" class="crm-reservation-side-action" wire:click="toggleAllActivity">{{ $showAllActivity ? 'Mostrar menos' : 'Ver todo' }}</button></div><div class="crm-reservation-activity">@foreach ($this->recentReservations as $recent)<div><span class="crm-user-avatar">{{ $recent->user?->initials() }}</span><p><strong>{{ $recent->user?->name }}</strong> registro {{ $recent->codeLabel() }}<small>{{ $recent->created_at?->diffForHumans() }}</small></p></div>@endforeach</div></section>
        </aside>
    </div>

    @if ($showForm)
        <div class="crm-reservation-modal" role="dialog" aria-modal="true" aria-labelledby="reservation-form-title" wire:click.self="closeForm">
            <div class="crm-reservation-modal__dialog" wire:click.stop>
                <div class="crm-reservation-modal__header"><div><p class="crm-eyebrow">Registro operativo</p><h2 id="reservation-form-title" class="crm-module-title">Nueva reserva</h2><p class="crm-module-description">Registra el bloqueo temporal de una propiedad para un lead calificado.</p></div><flux:button icon="x-mark" wire:click="closeForm">Cerrar</flux:button></div>
                <form wire:submit="save" class="crm-reservation-form">
                    <div class="crm-reservation-form__main">
                        <section class="crm-reservation-form-section"><div class="crm-reservation-form-section__heading"><span class="crm-reservation-form-icon"><i class="bi bi-file-earmark-text"></i></span><div><p class="crm-eyebrow">Informacion de la reserva</p><h3>Datos generales</h3></div></div><div class="crm-form-grid"><flux:input label="Codigo de reserva" value="Se genera automaticamente" disabled /><flux:select wire:model.live="form.operation_type" label="Tipo de operacion" required>@foreach (PropertyOperation::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select><flux:select wire:model.live="form.channel" label="Canal de registro" required><option value="PORTAL_WEB">Portal web</option><option value="REFERIDO">Referido</option><option value="OFICINA">Oficina</option><option value="WHATSAPP">WhatsApp</option></flux:select><flux:input wire:model.live="form.reserved_at" label="Fecha de reserva" type="datetime-local" required /><flux:input wire:model.live.debounce.200ms="form.expires_at" label="Fecha de expiracion" type="datetime-local" required /><flux:input wire:model.live.debounce.200ms="form.separation_amount" label="Monto de separacion" type="number" min="0" step="0.01" /><flux:select wire:model.live="form.currency" label="Moneda" required><option value="PEN">PEN - Sol peruano</option><option value="USD">USD - Dolar estadounidense</option></flux:select></div></section>
                        <section class="crm-reservation-form-section"><div class="crm-reservation-form-section__heading"><span class="crm-reservation-form-icon"><i class="bi bi-people"></i></span><div><p class="crm-eyebrow">Lead y cliente</p><h3>Relacion comercial</h3></div></div><div class="crm-form-grid"><flux:select wire:model.live="form.lead_id" label="Lead seleccionado" required><option value="">Seleccionar lead</option>@foreach ($this->leads as $lead)<option value="{{ $lead->id }}">{{ $lead->full_name }} · {{ $lead->phone }}</option>@endforeach</flux:select><flux:input label="Nombre completo" :value="$this->selectedLead?->full_name" disabled /><flux:input label="Telefono" :value="$this->selectedLead?->phone" disabled /><flux:input label="Correo electronico" :value="$this->selectedLead?->email" disabled /></div></section>
                        <section class="crm-reservation-form-section"><div class="crm-reservation-form-section__heading"><span class="crm-reservation-form-icon"><i class="bi bi-buildings"></i></span><div><p class="crm-eyebrow">Propiedad reservada</p><h3>Inmueble y disponibilidad</h3></div></div><div class="crm-form-grid"><flux:select wire:model.live="form.property_id" label="Propiedad" required><option value="">Seleccionar propiedad disponible</option>@foreach ($this->properties as $property)<option value="{{ $property->id }}">{{ $property->code }} · {{ $property->title }} · {{ $property->district }}</option>@endforeach</flux:select><flux:input label="Tipo de propiedad" :value="$this->selectedProperty?->type?->name" disabled /><flux:input label="Precio de lista" :value="$this->selectedProperty ? $this->selectedProperty->priceDisplay() : null" disabled /><flux:input label="Direccion / ubicacion" :value="$this->selectedProperty?->address" disabled /></div>@if ($this->selectedProperty) @php $selectedImage = $this->selectedProperty->images->first(); @endphp<div class="crm-reservation-property-summary">@if ($selectedImage)<img src="{{ Storage::url($selectedImage->storage_key) }}" alt="{{ $this->selectedProperty->title }}" />@else<span class="crm-reservation-property-placeholder"><i class="bi bi-image"></i></span>@endif<div><strong>{{ $this->selectedProperty->title }}</strong><span>{{ $this->selectedProperty->code }} · {{ $this->selectedProperty->district }}</span><small><i class="bi bi-check-circle-fill"></i> Propiedad disponible para reserva temporal</small></div></div>@endif</section>
                        <section class="crm-reservation-form-section"><div class="crm-reservation-form-section__heading"><span class="crm-reservation-form-icon"><i class="bi bi-person-check"></i></span><div><p class="crm-eyebrow">Responsables y aprobacion</p><h3>Asignacion de la reserva</h3></div></div><div class="crm-form-grid"><flux:select wire:model.live="form.advisor_user_id" label="Asesor responsable" required><option value="">Seleccionar asesor</option>@foreach ($this->advisors as $advisor)<option value="{{ $advisor->id }}">{{ $advisor->name }}</option>@endforeach</flux:select><flux:select wire:model.live="form.supervisor_user_id" label="Supervisor / aprobador"><option value="">Sin supervisor</option>@foreach ($this->supervisors as $supervisor)<option value="{{ $supervisor->id }}">{{ $supervisor->name }}</option>@endforeach</flux:select><flux:input wire:model.live.debounce.200ms="form.branch" label="Sede" placeholder="Lima, Miraflores..." /><flux:select wire:model.live="form.priority" label="Prioridad"><option value="LOW">Baja</option><option value="NORMAL">Media</option><option value="HIGH">Alta</option></flux:select></div><div class="crm-reservation-approval-toggle"><flux:checkbox wire:model.live="form.requires_approval" label="Requiere aprobacion del supervisor o administrador" :disabled="!$this->canApproveReservations()" /><span class="crm-badge {{ $form['requires_approval'] ? 'crm-badge--warning' : 'crm-badge--success' }}">{{ $form['requires_approval'] ? 'Pendiente de aprobacion' : 'Aprobada al registrar' }}</span></div><flux:textarea wire:model.live.debounce.200ms="form.reservation_reason" label="Motivo de la reserva" placeholder="Proceso de credito, separacion con documentos, negociacion..." /></section>
                        <section class="crm-reservation-form-section"><div class="crm-reservation-form-section__heading"><span class="crm-reservation-form-icon"><i class="bi bi-credit-card"></i></span><div><p class="crm-eyebrow">Pago de separacion</p><h3>Informacion del pago</h3></div></div><div class="crm-form-grid"><flux:select wire:model="form.payment_method" label="Metodo de pago"><option value="">Seleccionar</option><option value="TRANSFER">Transferencia bancaria</option><option value="CASH">Efectivo</option><option value="CARD">Tarjeta</option><option value="FINANCING">Financiamiento</option></flux:select><flux:input wire:model="form.payment_bank" label="Banco / entidad" /><flux:input wire:model="form.payment_operation" label="N. de operacion" /><flux:input wire:model="form.payment_date" label="Fecha de pago" type="datetime-local" /><flux:input wire:model="form.amount_paid" label="Monto pagado" type="number" min="0" step="0.01" /><flux:select wire:model="form.payment_status" label="Estado del pago"><option value="PENDING">Pendiente</option><option value="PARTIAL">Parcial</option><option value="CONFIRMED">Confirmado</option></flux:select></div><flux:textarea wire:model="form.payment_observation" label="Observacion del pago" /></section>
                        <section class="crm-reservation-form-section"><div class="crm-reservation-form-section__heading"><span class="crm-reservation-form-icon"><i class="bi bi-paperclip"></i></span><div><p class="crm-eyebrow">Documentacion adjunta</p><h3>Archivos de respaldo</h3></div></div><label class="crm-reservation-upload"><input type="file" wire:model="documents" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" /><i class="bi bi-cloud-arrow-up"></i><strong>Arrastra archivos aqui o haz clic para subir</strong><span>PDF, JPG, PNG, DOC, DOCX · Maximo 10 MB por archivo</span></label>@if ($documents !== [])<div class="crm-reservation-upload-list">@foreach ($documents as $document)<span><i class="bi bi-file-earmark-check"></i>{{ $document->getClientOriginalName() }}</span>@endforeach</div>@endif</section>
                    </div>
                    <aside class="crm-reservation-form__summary"><div class="crm-reservation-summary-card"><div class="crm-reservation-summary-card__heading"><i class="bi bi-card-checklist"></i><h3>Resumen de la reserva</h3></div>@if ($this->selectedLead)<div class="crm-reservation-summary-block"><p>Lead y cliente</p><strong>{{ $this->selectedLead->full_name }}</strong><span>{{ $this->selectedLead->phone }}</span><span>{{ $this->selectedLead->email ?: 'Sin correo registrado' }}</span></div>@else<div class="crm-reservation-summary-empty">Selecciona un lead para ver el resumen.</div>@endif<div class="crm-reservation-summary-block"><p>Propiedad</p>@if ($this->selectedProperty)<strong>{{ $this->selectedProperty->title }}</strong><span>{{ $this->selectedProperty->code }} · {{ $this->selectedProperty->district }}</span><span>{{ $this->selectedProperty->priceDisplay() }}</span>@else<div class="crm-reservation-summary-empty">Selecciona una propiedad disponible.</div>@endif</div><div class="crm-reservation-summary-block"><p>Detalles de la reserva</p><dl><div><dt>Estado</dt><dd>Pendiente de aprobacion</dd></div><div><dt>Vencimiento</dt><dd>{{ $form['expires_at'] ? Carbon::parse($form['expires_at'])->format('d/m/Y H:i') : '-' }}</dd></div><div><dt>Monto</dt><dd>{{ $form['currency'] }} {{ number_format((float) ($form['separation_amount'] ?: 0), 2) }}</dd></div><div><dt>Prioridad</dt><dd>{{ $form['priority'] === 'HIGH' ? 'Alta' : ($form['priority'] === 'LOW' ? 'Baja' : 'Media') }}</dd></div></dl></div><div class="crm-reservation-summary-availability"><i class="bi bi-check-circle-fill"></i><div><strong>Validacion automatica</strong><span>La propiedad se bloqueara temporalmente al guardar la reserva.</span></div></div></div><div class="crm-reservation-summary-card crm-reservation-rules"><div class="crm-reservation-summary-card__heading"><i class="bi bi-info-circle"></i><h3>Reglas de validacion</h3></div><ol><li>Una propiedad no puede tener dos reservas activas.</li><li>La fecha de expiracion debe ser posterior a la reserva.</li><li>El administrador o supervisor aprueba si corresponde.</li><li>El bloqueo se libera al cancelar o vencer.</li></ol></div></aside>
                    <div class="crm-reservation-form__footer"><flux:button type="button" wire:click="closeForm">Cancelar</flux:button><flux:button type="submit" variant="primary" icon="check">Guardar reserva</flux:button></div>
                </form>
            </div>
        </div>
    @endif
</section>
