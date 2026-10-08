<?php

use App\Actions\Sales\CreateSaleAction;
use App\Enums\CommissionStatus;
use App\Enums\PropertyStatus;
use App\Enums\SaleStatus;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Sale;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Ventas')] class extends Component
{
    public string $status = '';
    public bool $showForm = false;
    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('viewAny', Sale::class);
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->form = [
            'lead_id' => null,
            'property_id' => null,
            'advisor_user_id' => Auth::id(),
            'price' => null,
            'currency' => 'PEN',
            'sold_at' => now()->format('Y-m-d\TH:i'),
            'commission_percentage' => 3,
            'notes' => '',
        ];
    }

    public function create(): void
    {
        Gate::authorize('create', Sale::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function save(CreateSaleAction $createSale): void
    {
        Gate::authorize('create', Sale::class);

        $validated = $this->validate([
            'form.lead_id' => ['required', 'integer', 'exists:leads,id'],
            'form.property_id' => ['nullable', 'integer', 'exists:properties,id'],
            'form.advisor_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'form.price' => ['nullable', 'numeric', 'min:0'],
            'form.currency' => ['required', 'in:PEN,USD'],
            'form.sold_at' => ['required', 'date'],
            'form.commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'form.notes' => ['nullable', 'string', 'max:1000'],
        ])['form'];

        $lead = Lead::query()->findOrFail((int) $validated['lead_id']);
        Gate::authorize('view', $lead);

        if ($validated['property_id']) {
            $property = Property::query()->findOrFail((int) $validated['property_id']);
            Gate::authorize('view', $property);
        }

        $advisorUserId = $validated['advisor_user_id'] !== null
            ? (int) $validated['advisor_user_id']
            : (int) ($lead->assigned_user_id ?: Auth::id());

        abort_unless(Auth::user()->canAssignTo($advisorUserId), 403);

        try {
            $createSale->handle([
                'lead_id' => (int) $validated['lead_id'],
                'property_id' => $validated['property_id'] ? (int) $validated['property_id'] : null,
                'advisor_user_id' => $advisorUserId,
                'price' => $validated['price'],
                'currency' => $validated['currency'],
                'sold_at' => $validated['sold_at'],
                'commission_percentage' => (float) $validated['commission_percentage'],
                'notes' => $validated['notes'] ?: null,
            ], Auth::user());
        } catch (\RuntimeException $exception) {
            $this->addError('form.price', $exception->getMessage());

            return;
        }

        $this->showForm = false;
        $this->resetForm();
        Flux::toast(variant: 'success', text: 'Venta registrada.');
    }

    public function approve(int $saleId): void
    {
        $sale = Sale::query()->findOrFail($saleId);
        Gate::authorize('update', $sale);

        $sale->update(['status' => SaleStatus::Approved->value]);
        Flux::toast(variant: 'success', text: 'Venta aprobada.');
    }

    public function cancel(int $saleId): void
    {
        $sale = Sale::query()->with(['property', 'commission'])->findOrFail($saleId);
        Gate::authorize('update', $sale);

        DB::transaction(function () use ($sale): void {
            $sale->update(['status' => SaleStatus::Cancelled->value]);
            $sale->commission?->update(['status' => CommissionStatus::Cancelled->value]);

            $activeSaleExists = $sale->property_id !== null
                && Sale::query()
                    ->where('property_id', $sale->property_id)
                    ->whereNot('status', SaleStatus::Cancelled->value)
                    ->whereKeyNot($sale->id)
                    ->exists();

            if (! $activeSaleExists && $sale->property?->status === PropertyStatus::Sold->value) {
                $sale->property->update(['status' => PropertyStatus::Available->value]);
            }
        });

        Flux::toast(variant: 'success', text: 'Venta cancelada.');
    }

    #[Computed]
    public function sales(): \Illuminate\Support\Collection
    {
        return Sale::query()
            ->visibleFor(Auth::user())
            ->with(['lead', 'property', 'advisor', 'commission'])
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->latest('sold_at')
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
            ->whereNotIn('status', [PropertyStatus::Sold->value, PropertyStatus::Inactive->value])
            ->orderBy('title')
            ->limit(100)
            ->get(['id', 'code', 'title', 'district', 'price', 'currency', 'assigned_user_id']);
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
            <p class="crm-eyebrow">Operaciones</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Ventas</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Cierres comerciales, origen, propiedad y comision generada.</p>
        </div>
        <div class="crm-actions">
            <flux:select wire:model.live="status" label="Estado">
                <option value="">Todas</option>
                @foreach (SaleStatus::cases() as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </flux:select>
            @can('create', \App\Models\Sale::class)
                <flux:button icon="plus" variant="primary" wire:click="create">Nueva venta</flux:button>
            @endcan
        </div>
    </header>

    @if ($showForm)
        <section class="crm-card crm-panel-pad">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div><p class="crm-eyebrow">Cierre</p><h2 class="text-lg font-bold text-[var(--color-crm-navy)]">Registrar venta</h2></div>
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
                    <flux:select wire:model="form.property_id" label="Propiedad">
                        <option value="">Sin propiedad</option>
                        @foreach ($this->properties as $property)
                            <option value="{{ $property->id }}">{{ $property->code }} - {{ $property->title }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="form.advisor_user_id" label="Asesor">
                        <option value="">Usar asesor del lead</option>
                        @foreach ($this->advisors as $advisor)
                            <option value="{{ $advisor->id }}">{{ $advisor->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="form.price" label="Precio" type="number" step="0.01" />
                    <flux:select wire:model="form.currency" label="Moneda"><option value="PEN">PEN</option><option value="USD">USD</option></flux:select>
                    <flux:input wire:model="form.sold_at" label="Fecha de venta" type="datetime-local" required />
                    <flux:input wire:model="form.commission_percentage" label="% Comision" type="number" step="0.001" required />
                </div>
                <flux:textarea wire:model="form.notes" label="Notas" />
                <div class="crm-form-actions">
                    <flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary">Registrar</flux:button>
                </div>
            </form>
        </section>
    @endif

    <section class="crm-card crm-panel-pad">
        <div class="crm-table-wrap">
            <table class="crm-table">
                <thead><tr><th>Fecha</th><th>Lead</th><th>Propiedad</th><th>Asesor</th><th>Monto</th><th>Comision</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                    @forelse ($this->sales as $sale)
                        <tr wire:key="sale-{{ $sale->id }}">
                            <td>{{ $sale->sold_at?->format('d/m/Y') }}</td>
                            <td><a class="font-semibold text-[var(--color-crm-blue)]" href="{{ route('leads.show', $sale->lead) }}" wire:navigate>{{ $sale->lead?->full_name }}</a></td>
                            <td>
                                @if ($sale->property)
                                    <a class="font-semibold text-[var(--color-crm-blue)]" href="{{ route('properties.show', $sale->property) }}" wire:navigate>{{ $sale->property->title }}</a>
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{ $sale->advisor?->name ?? '-' }}</td>
                            <td>{{ $sale->priceDisplay() }}</td>
                            <td>{{ $sale->commission?->amountDisplay() ?? '-' }}</td>
                            <td><span class="crm-badge {{ $sale->status === 'APPROVED' ? 'crm-badge--success' : ($sale->status === 'CANCELLED' ? 'crm-badge--danger' : 'crm-badge--warning') }}">{{ $sale->statusLabel() }}</span></td>
                            <td>
                                @if ($sale->status === SaleStatus::Registered->value)
                                    <div class="crm-actions">
                                        <flux:button size="sm" wire:click="approve({{ $sale->id }})">Aprobar</flux:button>
                                        <flux:button size="sm" wire:click="cancel({{ $sale->id }})" wire:confirm="Cancelar esta venta?">Cancelar</flux:button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8"><div class="crm-empty">No hay ventas registradas.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</section>
