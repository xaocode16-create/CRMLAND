<?php

use App\Enums\PropertyOperation;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\PropertyType;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Propiedades')] class extends Component
{
    #[Url]
    public string $search = '';
    public string $status = '';
    public string $operation = '';
    public string $district = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('viewAny', Property::class);
        $this->resetForm();
    }

    public function resetForm(): void
    {
        $this->editingId = null;
        $this->form = [
            'title' => '',
            'description' => '',
            'property_type_id' => PropertyType::query()->oldest('id')->value('id'),
            'operation_type' => PropertyOperation::Sale->value,
            'district' => '',
            'address' => '',
            'price' => null,
            'currency' => 'PEN',
            'area_m2' => null,
            'bedrooms' => null,
            'bathrooms' => null,
            'parking_spaces' => null,
            'status' => PropertyStatus::Available->value,
            'owner_name' => '',
            'owner_phone' => '',
            'assigned_user_id' => Auth::user()->isAsesor() ? Auth::id() : null,
        ];
    }

    public function create(): void
    {
        Gate::authorize('create', Property::class);
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $propertyId): void
    {
        $property = Property::query()->findOrFail($propertyId);
        Gate::authorize('update', $property);
        $this->editingId = $property->id;
        $this->form = [
            'title' => $property->title,
            'description' => $property->description,
            'property_type_id' => $property->property_type_id,
            'operation_type' => $property->operation_type,
            'district' => $property->district,
            'address' => $property->address,
            'price' => $property->price,
            'currency' => $property->currency,
            'area_m2' => $property->area_m2,
            'bedrooms' => $property->bedrooms,
            'bathrooms' => $property->bathrooms,
            'parking_spaces' => $property->parking_spaces,
            'status' => $property->status,
            'owner_name' => $property->owner_name,
            'owner_phone' => $property->owner_phone,
            'assigned_user_id' => $property->assigned_user_id,
        ];
        $this->showForm = true;
    }

    public function save(): void
    {
        $property = $this->editingId ? Property::query()->findOrFail($this->editingId) : null;
        $property ? Gate::authorize('update', $property) : Gate::authorize('create', Property::class);

        $validated = $this->validate([
            'form.title' => ['required', 'string', 'max:180'],
            'form.description' => ['nullable', 'string'],
            'form.property_type_id' => ['required', 'integer', 'exists:property_types,id'],
            'form.operation_type' => ['required', Rule::in(array_column(PropertyOperation::cases(), 'value'))],
            'form.district' => ['required', 'string', 'max:120'],
            'form.address' => ['required', 'string', 'max:255'],
            'form.price' => ['required', 'numeric', 'min:0'],
            'form.currency' => ['required', Rule::in(['PEN', 'USD'])],
            'form.area_m2' => ['required', 'numeric', 'min:0'],
            'form.bedrooms' => ['nullable', 'integer', 'min:0'],
            'form.bathrooms' => ['nullable', 'integer', 'min:0'],
            'form.parking_spaces' => ['nullable', 'integer', 'min:0'],
            'form.status' => ['required', Rule::in(array_column(PropertyStatus::cases(), 'value'))],
            'form.owner_name' => ['nullable', 'string', 'max:160'],
            'form.owner_phone' => ['nullable', 'string', 'max:30'],
            'form.assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ])['form'];

        $assignedUserId = $validated['assigned_user_id'] !== null ? (int) $validated['assigned_user_id'] : null;
        abort_unless(Auth::user()->canAssignTo($assignedUserId), 403);

        if ($property instanceof Property) {
            $property->update($validated);
            Flux::toast(variant: 'success', text: 'Propiedad actualizada.');
        } else {
            $validated['code'] = $this->nextCode();
            $validated['created_by'] = Auth::id();
            Property::query()->create($validated);
            Flux::toast(variant: 'success', text: 'Propiedad creada.');
        }

        $this->showForm = false;
        $this->resetForm();
    }

    public function changeStatus(int $propertyId, string $status): void
    {
        $property = Property::query()->findOrFail($propertyId);
        Gate::authorize('update', $property);
        $property->update(['status' => $status]);
        Flux::toast(variant: 'success', text: 'Estado actualizado.');
    }

    #[Computed]
    public function properties(): \Illuminate\Support\Collection
    {
        return Property::query()
            ->visibleFor(Auth::user())
            ->with(['type', 'assignedUser', 'images'])
            ->withCount('leads')
            ->when($this->search !== '', fn ($query) => $query->where(function ($query) {
                $query->where('title', 'like', '%'.$this->search.'%')
                    ->orWhere('code', 'like', '%'.$this->search.'%');
            }))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->operation !== '', fn ($query) => $query->where('operation_type', $this->operation))
            ->when($this->district !== '', fn ($query) => $query->where('district', 'like', '%'.$this->district.'%'))
            ->latest()
            ->limit(80)
            ->get();
    }

    #[Computed]
    public function propertyTypes(): \Illuminate\Support\Collection
    {
        return PropertyType::query()->where('status', 'active')->orderBy('name')->get();
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

    private function nextCode(): string
    {
        $next = ((int) Property::query()->max('id')) + 1;

        return 'PROP-'.Str::padLeft((string) $next, 5, '0');
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Catalogo comercial</p>
            <h1 class="text-2xl font-bold text-[var(--color-crm-navy)]">Propiedades</h1>
            <p class="text-sm text-[var(--color-crm-muted)]">Inmuebles, proyectos, disponibilidad y asesor asignado.</p>
        </div>
        <div class="crm-actions">
            <flux:button icon="arrow-path" wire:click="$refresh">Actualizar</flux:button>
            @can('create', \App\Models\Property::class)
                <flux:button icon="plus" variant="primary" wire:click="create">Nueva propiedad</flux:button>
            @endcan
        </div>
    </header>

    <section class="crm-card overflow-hidden">
        <div class="grid gap-4 p-4 lg:grid-cols-[1fr_220px] lg:items-center">
            <div>
                <p class="crm-eyebrow">Ruta privada &gt; Propiedades</p>
                <h2 class="text-xl font-bold text-[var(--color-crm-navy)]">Mas que propiedades, conectamos personas</h2>
            </div>
            <img class="h-32 w-full rounded-lg object-cover" src="{{ asset('assets/img/casa.png') }}" alt="">
        </div>
    </section>

    <section class="crm-card">
        <div class="crm-toolbar">
            <flux:input wire:model.live.debounce.350ms="search" label="Buscar" placeholder="Codigo o titulo" />
            <flux:select wire:model.live="status" label="Estado"><option value="">Todos</option>@foreach (App\Enums\PropertyStatus::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
            <flux:select wire:model.live="operation" label="Operacion"><option value="">Todas</option>@foreach (App\Enums\PropertyOperation::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
            <flux:input wire:model.live.debounce.350ms="district" label="Distrito" />
        </div>
    </section>

    @if ($showForm)
        <section class="crm-card crm-panel-pad">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div><p class="crm-eyebrow">{{ $editingId ? 'Edicion' : 'Registro' }}</p><h2 class="text-lg font-bold text-[var(--color-crm-navy)]">{{ $editingId ? 'Editar propiedad' : 'Nueva propiedad' }}</h2></div>
                <flux:button icon="x-mark" wire:click="$set('showForm', false)">Cerrar</flux:button>
            </div>
            <form wire:submit="save" class="space-y-4">
                <div class="crm-form-grid">
                    <flux:input wire:model="form.title" label="Titulo" required />
                    <flux:select wire:model="form.property_type_id" label="Tipo" required>@foreach ($this->propertyTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</flux:select>
                    <flux:select wire:model="form.operation_type" label="Operacion">@foreach (App\Enums\PropertyOperation::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
                    <flux:select wire:model="form.status" label="Estado">@foreach (App\Enums\PropertyStatus::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
                    <flux:input wire:model="form.district" label="Distrito" required />
                    <flux:input wire:model="form.address" label="Direccion" required />
                    <flux:input wire:model="form.price" label="Precio" type="number" step="0.01" required />
                    <flux:select wire:model="form.currency" label="Moneda"><option value="PEN">PEN</option><option value="USD">USD</option></flux:select>
                    <flux:input wire:model="form.area_m2" label="Area m2" type="number" step="0.01" required />
                    <flux:input wire:model="form.bedrooms" label="Dormitorios" type="number" />
                    <flux:input wire:model="form.bathrooms" label="Banos" type="number" />
                    <flux:input wire:model="form.parking_spaces" label="Cocheras" type="number" />
                    <flux:select wire:model="form.assigned_user_id" label="Asesor"><option value="">Sin asignar</option>@foreach ($this->advisors as $advisor)<option value="{{ $advisor->id }}">{{ $advisor->name }}</option>@endforeach</flux:select>
                    <flux:input wire:model="form.owner_name" label="Propietario" />
                    <flux:input wire:model="form.owner_phone" label="Telefono propietario" />
                </div>
                <flux:textarea wire:model="form.description" label="Descripcion" />
                <div class="crm-form-actions">
                    <flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary">Guardar</flux:button>
                </div>
            </form>
        </section>
    @endif

    <section class="crm-grid crm-grid--cards">
        @forelse ($this->properties as $property)
            <article class="crm-card overflow-hidden" wire:key="property-{{ $property->id }}">
                <div class="h-40 bg-[var(--color-crm-bg-soft)]">
                    @php($image = $property->images->first())
                    @if ($image)
                        <img class="h-full w-full object-cover" src="{{ Storage::url($image->storage_key) }}" alt="{{ $property->title }}">
                    @else
                        <div class="flex h-full items-center justify-center text-[var(--color-crm-muted)]">Sin foto</div>
                    @endif
                </div>
                <div class="space-y-3 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <a class="font-bold text-[var(--color-crm-blue)]" href="{{ route('properties.show', $property) }}" wire:navigate>{{ $property->title }}</a>
                            <p class="text-sm text-[var(--color-crm-muted)]">{{ $property->code }} - {{ $property->district }}</p>
                        </div>
                        <span class="crm-badge {{ $property->status === 'AVAILABLE' ? 'crm-badge--success' : ($property->status === 'SOLD' ? 'crm-badge--danger' : 'crm-badge--warning') }}">{{ $property->statusLabel() }}</span>
                    </div>
                    <div class="flex flex-wrap gap-2 text-sm text-[var(--color-crm-muted)]">
                        <span>{{ $property->type?->name }}</span><span>{{ $property->operationLabel() }}</span><span>{{ $property->area_m2 }} m2</span>
                    </div>
                    <strong class="block text-xl text-[var(--color-crm-navy)]">{{ $property->priceDisplay() }}</strong>
                    <div class="crm-actions">
                        <flux:button size="sm" :href="route('properties.show', $property)" icon="eye" wire:navigate>Ver</flux:button>
                        @can('update', $property)<flux:button size="sm" icon="pencil-square" wire:click="edit({{ $property->id }})">Editar</flux:button>@endcan
                        @can('update', $property)
                            @if ($property->status !== 'INACTIVE')
                                <flux:button size="sm" icon="archive-box" wire:click="changeStatus({{ $property->id }}, 'INACTIVE')">Inactivar</flux:button>
                            @endif
                        @endcan
                    </div>
                </div>
            </article>
        @empty
            <section class="crm-card crm-empty">No hay propiedades con estos filtros.</section>
        @endforelse
    </section>
</section>
