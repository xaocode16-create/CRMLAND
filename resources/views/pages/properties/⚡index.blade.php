<?php

use App\Enums\PropertyOperation;
use App\Enums\PropertyStatus;
use App\Models\Property;
use App\Models\PropertyImage;
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
use Livewire\WithFileUploads;

new #[Title('Propiedades')] class extends Component
{
    use WithFileUploads;

    #[Url]
    public string $search = '';
    public string $status = '';
    public string $operation = '';
    public string $district = '';
    public bool $showForm = false;
    public ?int $editingId = null;
    public array $form = [];
    public array $photos = [];

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
            'region' => '',
            'province' => '',
            'district' => '',
            'address' => '',
            'location_reference' => '',
            'building_name' => '',
            'latitude' => null,
            'longitude' => null,
            'price' => null,
            'currency' => 'PEN',
            'area_m2' => null,
            'built_area_m2' => null,
            'bedrooms' => null,
            'bathrooms' => null,
            'parking_spaces' => null,
            'floors' => null,
            'floor_number' => null,
            'year_built' => null,
            'developer' => '',
            'project_status' => '',
            'project_type' => '',
            'total_units' => null,
            'minimum_area' => null,
            'maximum_area' => null,
            'price_from' => null,
            'price_to' => null,
            'project_currency' => 'PEN',
            'construction_start' => null,
            'delivery_date' => null,
            'financing' => '',
            'attributes_json' => '',
            'status' => PropertyStatus::Available->value,
            'owner_name' => '',
            'owner_phone' => '',
            'assigned_user_id' => Auth::user()->isAsesor() ? Auth::id() : null,
        ];
        $this->photos = [];
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
            'region' => $property->region,
            'province' => $property->province,
            'district' => $property->district,
            'address' => $property->address,
            'location_reference' => $property->location_reference,
            'building_name' => $property->building_name,
            'latitude' => $property->latitude,
            'longitude' => $property->longitude,
            'price' => $property->price,
            'currency' => $property->currency,
            'area_m2' => $property->area_m2,
            'built_area_m2' => $property->built_area_m2,
            'bedrooms' => $property->bedrooms,
            'bathrooms' => $property->bathrooms,
            'parking_spaces' => $property->parking_spaces,
            'floors' => $property->floors,
            'floor_number' => $property->floor_number,
            'year_built' => $property->year_built,
            'developer' => $property->developer,
            'project_status' => $property->project_status,
            'project_type' => $property->project_type,
            'total_units' => $property->total_units,
            'minimum_area' => $property->minimum_area,
            'maximum_area' => $property->maximum_area,
            'price_from' => $property->price_from,
            'price_to' => $property->price_to,
            'project_currency' => $property->project_currency ?? 'PEN',
            'construction_start' => $property->construction_start?->format('Y-m-d'),
            'delivery_date' => $property->delivery_date?->format('Y-m-d'),
            'financing' => $property->financing,
            'attributes_json' => $property->attributes_json
                ? (json_encode($property->attributes_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '')
                : '',
            'status' => $property->status,
            'owner_name' => $property->owner_name,
            'owner_phone' => $property->owner_phone,
            'assigned_user_id' => $property->assigned_user_id,
        ];
        $this->photos = [];
        $this->showForm = true;
    }

    public function updatedFormPropertyTypeId(mixed $value): void
    {
        $this->clearUnusedTypeFields();
        $this->resetValidation();
    }

    public function save(): void
    {
        $property = $this->editingId ? Property::query()->findOrFail($this->editingId) : null;
        $property ? Gate::authorize('update', $property) : Gate::authorize('create', Property::class);

        $rules = [
            'form.title' => ['required', 'string', 'max:180'],
            'form.description' => ['nullable', 'string'],
            'form.property_type_id' => ['required', 'integer', 'exists:property_types,id'],
            'form.operation_type' => ['required', Rule::in(array_column(PropertyOperation::cases(), 'value'))],
            'form.region' => ['nullable', 'string', 'max:120'],
            'form.province' => ['nullable', 'string', 'max:120'],
            'form.district' => ['required', 'string', 'max:120'],
            'form.address' => ['required', 'string', 'max:255'],
            'form.location_reference' => ['nullable', 'string', 'max:255'],
            'form.building_name' => ['nullable', 'string', 'max:160'],
            'form.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'form.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'form.price' => ['nullable', 'numeric', 'min:0'],
            'form.currency' => ['nullable', Rule::in(['PEN', 'USD'])],
            'form.area_m2' => ['nullable', 'numeric', 'min:0'],
            'form.built_area_m2' => ['nullable', 'numeric', 'min:0'],
            'form.bedrooms' => ['nullable', 'integer', 'min:0'],
            'form.bathrooms' => ['nullable', 'integer', 'min:0'],
            'form.parking_spaces' => ['nullable', 'integer', 'min:0'],
            'form.floors' => ['nullable', 'integer', 'min:0'],
            'form.floor_number' => ['nullable', 'integer', 'min:0'],
            'form.year_built' => ['nullable', 'integer', 'min:1800', 'max:'.now()->year],
            'form.developer' => ['nullable', 'string', 'max:180'],
            'form.project_status' => ['nullable', 'string', 'max:60'],
            'form.project_type' => ['nullable', 'string', 'max:60'],
            'form.total_units' => ['nullable', 'integer', 'min:1'],
            'form.minimum_area' => ['nullable', 'numeric', 'min:0'],
            'form.maximum_area' => ['nullable', 'numeric', 'gte:form.minimum_area'],
            'form.price_from' => ['nullable', 'numeric', 'min:0'],
            'form.price_to' => ['nullable', 'numeric', 'gte:form.price_from'],
            'form.project_currency' => ['nullable', Rule::in(['PEN', 'USD'])],
            'form.construction_start' => ['nullable', 'date'],
            'form.delivery_date' => ['nullable', 'date', 'after_or_equal:form.construction_start'],
            'form.financing' => ['nullable', 'string', 'max:120'],
            'form.attributes_json' => ['nullable', 'json'],
            'form.status' => ['required', Rule::in(array_column(PropertyStatus::cases(), 'value'))],
            'form.owner_name' => ['nullable', 'string', 'max:160'],
            'form.owner_phone' => ['nullable', 'string', 'max:30'],
            'form.assigned_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'photos' => ['nullable', 'array', 'max:12'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];

        if ($this->isProjectType()) {
            $rules['form.developer'] = ['required', 'string', 'max:180'];
            $rules['form.project_status'] = ['required', 'string', 'max:60'];
            $rules['form.project_type'] = ['required', 'string', 'max:60'];
            $rules['form.total_units'] = ['required', 'integer', 'min:1'];
            $rules['form.minimum_area'] = ['required', 'numeric', 'min:0'];
            $rules['form.price_from'] = ['required', 'numeric', 'min:0'];
            $rules['form.project_currency'] = ['required', Rule::in(['PEN', 'USD'])];
        } else {
            $rules['form.price'] = ['required', 'numeric', 'min:0'];
            $rules['form.currency'] = ['required', Rule::in(['PEN', 'USD'])];
            $rules['form.area_m2'] = ['required', 'numeric', 'min:0'];
        }

        $validated = $this->validate($rules);
        $propertyData = $validated['form'];

        if ($propertyData['attributes_json'] !== null && trim((string) $propertyData['attributes_json']) !== '') {
            $attributes = json_decode((string) $propertyData['attributes_json'], true);

            if (! is_array($attributes)) {
                $this->addError('form.attributes_json', 'Los atributos deben ser un objeto JSON.');

                return;
            }

            $propertyData['attributes_json'] = $attributes;
        } else {
            $propertyData['attributes_json'] = null;
        }

        if ($this->isProjectType()) {
            $propertyData['price'] = $propertyData['price_from'];
            $propertyData['currency'] = $propertyData['project_currency'];
            $propertyData['area_m2'] = $propertyData['minimum_area'];
        }

        $assignedUserId = $propertyData['assigned_user_id'] !== null ? (int) $propertyData['assigned_user_id'] : null;
        abort_unless(Auth::user()->canAssignTo($assignedUserId), 403);

        if ($this->photos !== []) {
            Gate::authorize('properties.upload_images');
        }

        if ($property instanceof Property) {
            $property->update($propertyData);
            Flux::toast(variant: 'success', text: 'Propiedad actualizada.');
        } else {
            $propertyData['code'] = $this->nextCode();
            $propertyData['created_by'] = Auth::id();
            $property = Property::query()->create($propertyData);
            Flux::toast(variant: 'success', text: 'Propiedad creada.');
        }

        $this->storeImages($property);

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
    public function selectedType(): ?PropertyType
    {
        $propertyTypeId = $this->form['property_type_id'] ?? null;

        return $propertyTypeId ? $this->propertyTypes->firstWhere('id', (int) $propertyTypeId) : null;
    }

    public function selectedTypeSlug(): string
    {
        return $this->selectedType?->slug ?? '';
    }

    public function typeUses(string $field): bool
    {
        return in_array($field, $this->typeSpecificFields(), true);
    }

    public function isProjectType(): bool
    {
        return $this->selectedTypeSlug() === 'proyecto-inmobiliario';
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

    /**
     * @return string[]
     */
    private function typeSpecificFields(): array
    {
        return match ($this->selectedTypeSlug()) {
            'casa', 'casa-de-playa' => [
                'built_area_m2', 'bedrooms', 'bathrooms', 'parking_spaces', 'floors', 'year_built',
            ],
            'departamento' => [
                'building_name', 'built_area_m2', 'bedrooms', 'bathrooms', 'parking_spaces', 'floor_number', 'year_built',
            ],
            'duplex' => [
                'built_area_m2', 'bedrooms', 'bathrooms', 'parking_spaces', 'floors', 'floor_number', 'year_built',
            ],
            'local-comercial' => [
                'built_area_m2', 'parking_spaces', 'floors', 'floor_number', 'year_built',
            ],
            'oficina' => [
                'building_name', 'built_area_m2', 'parking_spaces', 'floor_number', 'year_built',
            ],
            'proyecto-inmobiliario' => [
                'developer', 'project_status', 'project_type', 'total_units', 'minimum_area', 'maximum_area',
                'price_from', 'price_to', 'project_currency', 'construction_start', 'delivery_date', 'financing',
            ],
            'terreno' => [],
            default => [
                'built_area_m2', 'bedrooms', 'bathrooms', 'parking_spaces', 'floors', 'floor_number', 'year_built',
            ],
        };
    }

    private function clearUnusedTypeFields(): void
    {
        $typeSpecificFields = [
            'building_name', 'built_area_m2', 'bedrooms', 'bathrooms', 'parking_spaces', 'floors', 'floor_number',
            'year_built', 'developer', 'project_status', 'project_type', 'total_units', 'minimum_area', 'maximum_area',
            'price_from', 'price_to', 'project_currency', 'construction_start', 'delivery_date', 'financing',
        ];

        foreach ($typeSpecificFields as $field) {
            if (! $this->typeUses($field)) {
                $this->form[$field] = $field === 'project_currency' ? 'PEN' : null;
            }
        }
    }

    private function storeImages(Property $property): void
    {
        if ($this->photos === []) {
            return;
        }

        $hasPrimaryImage = $property->images()->where('is_primary', true)->exists();
        $sortOrder = ((int) $property->images()->max('sort_order')) + 1;

        foreach ($this->photos as $photo) {
            $path = $photo->store('properties/'.$property->id, 'public');
            $dimensions = getimagesize($photo->getRealPath());

            PropertyImage::query()->create([
                'property_id' => $property->id,
                'file_name' => $photo->getClientOriginalName(),
                'storage_key' => $path,
                'mime_type' => $photo->getMimeType() ?: 'image/jpeg',
                'file_size' => $photo->getSize(),
                'width' => is_array($dimensions) ? ($dimensions[0] ?? null) : null,
                'height' => is_array($dimensions) ? ($dimensions[1] ?? null) : null,
                'is_primary' => ! $hasPrimaryImage,
                'sort_order' => $sortOrder,
            ]);

            $hasPrimaryImage = true;
            $sortOrder++;
        }
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Catalogo comercial</p>
            <h1 class="crm-module-title">Propiedades</h1>
            <p class="crm-module-description">Inmuebles, proyectos, disponibilidad y asesor asignado.</p>
        </div>
        <div class="crm-actions">
            <flux:button icon="arrow-path" wire:click="$refresh">Actualizar</flux:button>
            @can('create', \App\Models\Property::class)
                <flux:button icon="plus" variant="primary" wire:click="create">Nueva propiedad</flux:button>
            @endcan
        </div>
    </header>

    <section class="crm-card crm-propiedades-section-01">
        <div class="lg:grid-cols-[1fr_220px] lg:items-center crm-propiedades-div-01">
            <div>
                <p class="crm-eyebrow">Ruta privada &gt; Propiedades</p>
                <h2 class="crm-propiedades-h2-01">Mas que propiedades, conectamos personas</h2>
            </div>
            <img class="crm-propiedades-img-01" src="{{ asset('assets/img/casa.png') }}" alt="">
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
            <div class="crm-section-heading">
                <div><p class="crm-eyebrow">{{ $editingId ? 'Edicion' : 'Registro' }}</p><h2 class="crm-section-title">{{ $editingId ? 'Editar propiedad' : 'Nueva propiedad' }}</h2></div>
                <flux:button icon="x-mark" wire:click="$set('showForm', false)">Cerrar</flux:button>
            </div>
            <form wire:submit="save" class="crm-form-stack">
                <div class="crm-propiedades-form-section">
                    <div>
                        <p class="crm-eyebrow">Identificacion</p>
                        <h3 class="crm-propiedades-form-section-title">Datos generales</h3>
                    </div>
                    <div class="crm-form-grid">
                    <flux:input wire:model="form.title" label="Titulo" required />
                    <flux:select wire:model.live="form.property_type_id" label="Tipo" required>@foreach ($this->propertyTypes as $type)<option value="{{ $type->id }}">{{ $type->name }}</option>@endforeach</flux:select>
                    <flux:select wire:model="form.operation_type" label="Operacion">@foreach (App\Enums\PropertyOperation::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
                    <flux:select wire:model="form.status" label="Estado">@foreach (App\Enums\PropertyStatus::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</flux:select>
                    </div>
                </div>

                <div class="crm-propiedades-form-section">
                    <div>
                        <p class="crm-eyebrow">Ubicacion</p>
                        <h3 class="crm-propiedades-form-section-title">Donde se encuentra la propiedad</h3>
                    </div>
                    <div class="crm-form-grid">
                    <flux:input wire:model="form.region" label="Region" />
                    <flux:input wire:model="form.province" label="Provincia" />
                    <flux:input wire:model="form.district" label="Distrito" required />
                    <flux:input wire:model="form.address" label="Direccion" required />
                    <flux:input wire:model="form.location_reference" label="Referencia de ubicacion" />
                    <flux:input wire:model="form.latitude" label="Latitud" type="number" step="0.00000001" placeholder="-90 a 90" />
                    <flux:input wire:model="form.longitude" label="Longitud" type="number" step="0.00000001" placeholder="-180 a 180" />
                    </div>
                </div>

                <div wire:key="property-specific-fields-{{ $this->selectedTypeSlug() }}">
                @if ($this->isProjectType())
                    <div class="crm-propiedades-form-section">
                        <div>
                            <p class="crm-eyebrow">Proyecto inmobiliario</p>
                            <h3 class="crm-propiedades-form-section-title">Informacion del proyecto</h3>
                        </div>
                        <div class="crm-form-grid">
                            <flux:input wire:model="form.developer" label="Desarrollador" required />
                            <flux:input wire:model="form.project_type" label="Tipo de proyecto" placeholder="Residencial, mixto..." required />
                            <flux:input wire:model="form.project_status" label="Estado del proyecto" placeholder="En planos, en construccion..." required />
                            <flux:input wire:model="form.total_units" label="Total de unidades" type="number" min="1" required />
                            <flux:input wire:model="form.minimum_area" label="Area minima m2" type="number" step="0.01" required />
                            <flux:input wire:model="form.maximum_area" label="Area maxima m2" type="number" step="0.01" />
                            <flux:input wire:model="form.price_from" label="Precio desde" type="number" step="0.01" required />
                            <flux:input wire:model="form.price_to" label="Precio hasta" type="number" step="0.01" />
                            <flux:select wire:model="form.project_currency" label="Moneda del proyecto" required><option value="PEN">PEN</option><option value="USD">USD</option></flux:select>
                            <flux:input wire:model="form.construction_start" label="Inicio de construccion" type="date" />
                            <flux:input wire:model="form.delivery_date" label="Fecha de entrega" type="date" />
                            <flux:input wire:model="form.financing" label="Financiamiento" placeholder="Bancos, cuotas, contado..." />
                        </div>
                    </div>
                @else
                    <div class="crm-propiedades-form-section">
                        <div>
                            <p class="crm-eyebrow">Comercial</p>
                            <h3 class="crm-propiedades-form-section-title">Precio y dimensiones</h3>
                        </div>
                        <div class="crm-form-grid">
                            <flux:input wire:model="form.price" label="Precio" type="number" step="0.01" required />
                            <flux:select wire:model="form.currency" label="Moneda" required><option value="PEN">PEN</option><option value="USD">USD</option></flux:select>
                            <flux:input wire:model="form.area_m2" label="Area total m2" type="number" step="0.01" required />
                            @if ($this->typeUses('built_area_m2'))
                                <flux:input wire:model="form.built_area_m2" label="Area construida m2" type="number" step="0.01" />
                            @endif
                            @if ($this->typeUses('bedrooms'))
                                <flux:input wire:model="form.bedrooms" label="Dormitorios" type="number" min="0" />
                            @endif
                            @if ($this->typeUses('bathrooms'))
                                <flux:input wire:model="form.bathrooms" label="Banos" type="number" min="0" />
                            @endif
                            @if ($this->typeUses('parking_spaces'))
                                <flux:input wire:model="form.parking_spaces" label="Cocheras" type="number" min="0" />
                            @endif
                            @if ($this->typeUses('floors'))
                                <flux:input wire:model="form.floors" label="Niveles" type="number" min="0" />
                            @endif
                            @if ($this->typeUses('building_name'))
                                <flux:input wire:model="form.building_name" label="Edificio" />
                            @endif
                            @if ($this->typeUses('floor_number'))
                                <flux:input wire:model="form.floor_number" label="Piso" type="number" min="0" />
                            @endif
                            @if ($this->typeUses('year_built'))
                                <flux:input wire:model="form.year_built" label="Año de construccion" type="number" min="1800" max="{{ now()->year }}" />
                            @endif
                        </div>
                    </div>
                @endif
                </div>

                <div class="crm-propiedades-form-section">
                    <div>
                        <p class="crm-eyebrow">Responsables</p>
                        <h3 class="crm-propiedades-form-section-title">Propietario y asesor</h3>
                    </div>
                    <div class="crm-form-grid">
                        <flux:select wire:model="form.assigned_user_id" label="Asesor"><option value="">Sin asignar</option>@foreach ($this->advisors as $advisor)<option value="{{ $advisor->id }}">{{ $advisor->name }}</option>@endforeach</flux:select>
                        <flux:input wire:model="form.owner_name" label="Propietario" />
                        <flux:input wire:model="form.owner_phone" label="Telefono propietario" />
                    </div>
                </div>

                <flux:textarea wire:model="form.description" label="Descripcion" />

                <div class="crm-propiedades-form-section">
                    <div>
                        <p class="crm-eyebrow">Galeria</p>
                        <h3 class="crm-propiedades-form-section-title">Imagenes de la propiedad</h3>
                        <p class="crm-module-description">Acepta JPG, PNG o WEBP en cualquier proporcion. Se guardan automaticamente sus dimensiones, peso y orden.</p>
                    </div>
                    @can('properties.upload_images')
                        <flux:input wire:model="photos" type="file" label="Seleccionar imagenes" multiple accept="image/jpeg,image/png,image/webp" />
                        <p class="crm-propiedades-upload-help">Maximo 12 imagenes de hasta 10 MB cada una. La primera sera la imagen principal.</p>
                        @if ($photos !== [])
                            <div class="crm-propiedades-image-preview-grid">
                                @foreach ($photos as $photo)
                                    <div class="crm-propiedades-image-preview" wire:key="new-photo-{{ $loop->index }}">
                                        <img src="{{ $photo->temporaryUrl() }}" alt="Vista previa de {{ $photo->getClientOriginalName() }}">
                                        <span>{{ $photo->getClientOriginalName() }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p class="crm-module-description">Tu perfil puede registrar la propiedad, pero no tiene permiso para cargar imagenes.</p>
                    @endcan
                </div>

                <div class="crm-propiedades-form-section">
                    <div>
                        <p class="crm-eyebrow">Campos adicionales</p>
                        <h3 class="crm-propiedades-form-section-title">Atributos personalizados</h3>
                    </div>
                    <flux:textarea wire:model="form.attributes_json" label="Atributos JSON" placeholder="{&quot;vista&quot;: &quot;Parque&quot;, &quot;mascotas&quot;: true}" />
                </div>

                <div class="crm-form-actions">
                    <flux:button type="button" wire:click="$set('showForm', false)">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary">Guardar</flux:button>
                </div>
            </form>
        </section>
    @endif

    <section class="crm-grid crm-grid--cards">
        @forelse ($this->properties as $property)
            <article class="crm-card crm-propiedades-section-01" wire:key="property-{{ $property->id }}">
                <div class="crm-propiedades-div-03">
                    @php($image = $property->images->first())
                    @if ($image)
                        <img class="crm-propiedades-img-02" src="{{ Storage::url($image->storage_key) }}" alt="{{ $property->title }}">
                    @else
                        <div class="crm-propiedades-div-04">Sin foto</div>
                    @endif
                </div>
                <div class="crm-propiedades-div-05">
                    <div class="crm-propiedades-div-06">
                        <div>
                            <a class="crm-propiedades-a-01" href="{{ route('properties.show', $property) }}" wire:navigate>{{ $property->title }}</a>
                            <p class="crm-module-description">{{ $property->code }} - {{ $property->district }}</p>
                        </div>
                        <span class="crm-badge {{ $property->status === 'AVAILABLE' ? 'crm-badge--success' : ($property->status === 'SOLD' ? 'crm-badge--danger' : 'crm-badge--warning') }}">{{ $property->statusLabel() }}</span>
                    </div>
                    <div class="crm-propiedades-div-07">
                        <span>{{ $property->type?->name }}</span><span>{{ $property->operationLabel() }}</span><span>{{ $property->area_m2 }} m2</span>
                    </div>
                    <strong class="crm-propiedades-strong-01">{{ $property->priceDisplay() }}</strong>
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
