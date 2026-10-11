<?php

use App\Enums\PropertyStatus;
use App\Models\Lead;
use App\Models\Property;
use App\Models\PropertyImage;
use App\Models\PropertyUnit;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Detalle de propiedad')] class extends Component
{
    use WithFileUploads;

    public Property $property;
    public array $photos = [];
    public ?int $leadId = null;
    public string $relationType = 'INTERESTED';
    public bool $showUnitForm = false;
    public array $unitForm = [];

    public function mount(Property $property): void
    {
        Gate::authorize('view', $property);
        $this->property = $property;
        $this->resetUnitForm();
    }

    public function uploadImages(): void
    {
        Gate::authorize('properties.upload_images');
        Gate::authorize('update', $this->record);

        $validated = $this->validate([
            'photos' => ['required', 'array', 'max:8'],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $hasPrimaryImage = $this->record->images->contains('is_primary', true);

        foreach ($validated['photos'] as $photo) {
            $path = $photo->store('properties/'.$this->property->id, 'public');
            $dimensions = @getimagesize($photo->getRealPath()) ?: [null, null];

            PropertyImage::query()->create([
                'property_id' => $this->property->id,
                'file_name' => $photo->getClientOriginalName(),
                'storage_key' => $path,
                'mime_type' => $photo->getMimeType() ?: 'image/jpeg',
                'file_size' => $photo->getSize(),
                'width' => $dimensions[0],
                'height' => $dimensions[1],
                'is_primary' => ! $hasPrimaryImage,
                'sort_order' => $this->record->images->count() + 1,
            ]);

            $hasPrimaryImage = true;
        }

        $this->photos = [];
        Flux::toast(variant: 'success', text: 'Imagenes cargadas.');
    }

    public function setPrimary(int $imageId): void
    {
        Gate::authorize('properties.upload_images');
        Gate::authorize('update', $this->record);

        PropertyImage::query()
            ->where('property_id', $this->property->id)
            ->update(['is_primary' => false]);

        PropertyImage::query()
            ->where('property_id', $this->property->id)
            ->whereKey($imageId)
            ->update(['is_primary' => true]);

        Flux::toast(variant: 'success', text: 'Imagen principal actualizada.');
    }

    public function deleteImage(int $imageId): void
    {
        Gate::authorize('properties.delete_images');
        Gate::authorize('update', $this->record);

        $image = PropertyImage::query()
            ->where('property_id', $this->property->id)
            ->findOrFail($imageId);

        Storage::disk('public')->delete($image->storage_key);
        $image->delete();

        Flux::toast(variant: 'success', text: 'Imagen eliminada.');
    }

    public function attachLead(): void
    {
        Gate::authorize('properties.assign_lead');

        $validated = $this->validate([
            'leadId' => ['required', 'integer', 'exists:leads,id'],
            'relationType' => ['required', Rule::in(['INTERESTED', 'SUGGESTED', 'VISITED', 'NEGOTIATING', 'REJECTED'])],
        ]);

        $lead = Lead::query()->findOrFail((int) $validated['leadId']);
        Gate::authorize('view', $lead);

        $this->record->leads()->syncWithoutDetaching([
            $lead->id => [
                'relation_type' => $validated['relationType'],
                'created_by' => Auth::id(),
                'created_at' => now(),
            ],
        ]);

        $this->leadId = null;
        Flux::toast(variant: 'success', text: 'Lead asociado.');
    }

    public function resetUnitForm(): void
    {
        $this->unitForm = [
            'unit_number' => '',
            'unit_type' => '',
            'tower' => '',
            'floor' => '',
            'area_m2' => null,
            'bedrooms' => null,
            'bathrooms' => null,
            'parking_spaces' => null,
            'price' => null,
            'currency' => 'PEN',
            'status' => PropertyStatus::Available->value,
        ];
    }

    public function toggleUnitForm(): void
    {
        $this->showUnitForm = ! $this->showUnitForm;
    }

    public function addUnit(): void
    {
        Gate::authorize('update', $this->record);

        $validated = $this->validate([
            'unitForm.unit_number' => [
                'required',
                'string',
                'max:80',
                Rule::unique('property_units', 'unit_number')->where('property_id', $this->property->id),
            ],
            'unitForm.unit_type' => ['nullable', 'string', 'max:80'],
            'unitForm.tower' => ['nullable', 'string', 'max:80'],
            'unitForm.floor' => ['nullable', 'string', 'max:40'],
            'unitForm.area_m2' => ['nullable', 'numeric', 'min:0'],
            'unitForm.bedrooms' => ['nullable', 'integer', 'min:0'],
            'unitForm.bathrooms' => ['nullable', 'integer', 'min:0'],
            'unitForm.parking_spaces' => ['nullable', 'integer', 'min:0'],
            'unitForm.price' => ['nullable', 'numeric', 'min:0'],
            'unitForm.currency' => ['required', Rule::in(['PEN', 'USD'])],
            'unitForm.status' => ['required', Rule::in(array_column(PropertyStatus::cases(), 'value'))],
        ])['unitForm'];

        PropertyUnit::query()->create([
            ...$validated,
            'property_id' => $this->property->id,
        ]);

        $this->showUnitForm = false;
        $this->resetUnitForm();
        Flux::toast(variant: 'success', text: 'Unidad registrada.');
    }

    public function changeUnitStatus(int $unitId, string $status): void
    {
        Gate::authorize('update', $this->record);

        PropertyUnit::query()
            ->where('property_id', $this->property->id)
            ->whereKey($unitId)
            ->update(['status' => $status]);

        Flux::toast(variant: 'success', text: 'Unidad actualizada.');
    }

    #[Computed]
    public function record(): Property
    {
        return Property::query()->with([
            'type',
            'assignedUser',
            'creator',
            'images',
            'units',
            'leads.assignedUser',
        ])->findOrFail($this->property->id);
    }

    #[Computed]
    public function leadOptions(): \Illuminate\Support\Collection
    {
        return Lead::query()
            ->visibleFor(Auth::user())
            ->with('assignedUser')
            ->orderBy('full_name')
            ->limit(100)
            ->get(['id', 'full_name', 'phone', 'assigned_user_id']);
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">{{ $this->record->code }}</p>
            <h1 class="crm-module-title">{{ $this->record->title }}</h1>
            <p class="crm-module-description">{{ $this->record->district }} - {{ $this->record->operationLabel() }}</p>
        </div>
        <div class="crm-actions">
            <flux:button :href="route('properties.index')" icon="arrow-left" wire:navigate>Volver</flux:button>
            <flux:button icon="arrow-path" wire:click="$refresh">Actualizar</flux:button>
        </div>
    </header>

    <div class="crm-grid crm-grid--stats">
        <article class="crm-card crm-stat"><span>Precio</span><strong class="crm-propiedades-strong-02">{{ $this->record->priceDisplay() }}</strong></article>
        <article class="crm-card crm-stat"><span>Estado</span><strong class="crm-propiedades-strong-02">{{ $this->record->statusLabel() }}</strong></article>
        <article class="crm-card crm-stat"><span>Area</span><strong class="crm-propiedades-strong-02">{{ $this->record->area_m2 }} m2</strong></article>
        <article class="crm-card crm-stat"><span>Asesor</span><strong class="crm-propiedades-strong-02">{{ $this->record->assignedUser?->name ?? 'Sin asignar' }}</strong></article>
    </div>

    <div class="xl:grid-cols-[1.15fr_0.85fr] crm-grid">
        <section class="crm-card crm-panel-pad crm-form-stack">
            <div>
                <p class="crm-eyebrow">Galeria</p>
                <h2 class="crm-section-title">Imagenes de la propiedad</h2>
            </div>

            <div class="md:grid-cols-2 crm-propiedades-div-09">
                @forelse ($this->record->images as $image)
                    <article class="crm-propiedades-article-01" wire:key="image-{{ $image->id }}">
                        <img class="crm-propiedades-img-03" src="{{ Storage::url($image->storage_key) }}" alt="{{ $image->file_name }}">
                        <div class="crm-propiedades-div-10">
                            <div>
                                <span class="crm-badge {{ $image->is_primary ? 'crm-badge--success' : '' }}">{{ $image->is_primary ? 'Principal' : 'Galeria' }}</span>
                                <span class="crm-propiedades-image-meta">{{ $image->width && $image->height ? $image->width.' x '.$image->height.' px' : 'Dimensiones no disponibles' }}</span>
                            </div>
                            <div class="crm-actions">
                                @if (! $image->is_primary)
                                    <flux:button size="sm" wire:click="setPrimary({{ $image->id }})">Principal</flux:button>
                                @endif
                                <flux:button size="sm" icon="trash" wire:click="deleteImage({{ $image->id }})" wire:confirm="Eliminar esta imagen?">Eliminar</flux:button>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="crm-empty md:col-span-2">Sin imagenes cargadas.</div>
                @endforelse
            </div>

            <form wire:submit="uploadImages" class="crm-propiedades-form-02">
                <flux:input wire:model="photos" type="file" label="Subir imagenes" multiple />
                <div class="crm-form-actions crm-propiedades-div-11">
                    <flux:button type="submit" variant="primary" icon="arrow-up-tray">Subir</flux:button>
                </div>
            </form>
        </section>

        <aside class="crm-form-stack">
            <section class="crm-card crm-panel-pad crm-form-stack">
                <div>
                    <p class="crm-eyebrow">Datos</p>
                    <h2 class="crm-section-title">Informacion comercial</h2>
                </div>
                <div class="crm-form-grid crm-propiedades-div-12">
                    <div><span class="crm-propiedades-span-01">Tipo</span><strong class="crm-propiedades-strong-03">{{ $this->record->type?->name }}</strong></div>
                    <div><span class="crm-propiedades-span-01">Direccion</span><strong class="crm-propiedades-strong-03">{{ $this->record->address }}</strong></div>
                    <div><span class="crm-propiedades-span-01">Dormitorios</span><strong class="crm-propiedades-strong-03">{{ $this->record->bedrooms ?? '-' }}</strong></div>
                    <div><span class="crm-propiedades-span-01">Banos</span><strong class="crm-propiedades-strong-03">{{ $this->record->bathrooms ?? '-' }}</strong></div>
                    <div><span class="crm-propiedades-span-01">Cocheras</span><strong class="crm-propiedades-strong-03">{{ $this->record->parking_spaces ?? '-' }}</strong></div>
                    <div><span class="crm-propiedades-span-01">Propietario</span><strong class="crm-propiedades-strong-03">{{ $this->record->owner_name ?: '-' }}</strong></div>
                </div>
                <p class="crm-propiedades-p-02">{{ $this->record->description ?: 'Sin descripcion registrada.' }}</p>
            </section>

            <section class="crm-card crm-panel-pad">
                <p class="crm-eyebrow">Leads asociados</p>
                <form wire:submit="attachLead" class="md:grid-cols-[1fr_170px_auto] crm-propiedades-form-03">
                    <flux:select wire:model="leadId" label="Lead">
                        <option value="">Seleccionar</option>
                        @foreach ($this->leadOptions as $lead)
                            <option value="{{ $lead->id }}">{{ $lead->full_name }} - {{ $lead->phone }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="relationType" label="Relacion">
                        <option value="INTERESTED">Interesado</option>
                        <option value="SUGGESTED">Sugerida</option>
                        <option value="VISITED">Visitada</option>
                        <option value="NEGOTIATING">Negociando</option>
                        <option value="REJECTED">Rechazada</option>
                    </flux:select>
                    <div class="crm-propiedades-div-13"><flux:button type="submit" variant="primary">Asociar</flux:button></div>
                </form>
                <div class="crm-propiedades-div-14">
                    @forelse ($this->record->leads as $lead)
                        <article class="crm-inset-card" wire:key="property-lead-{{ $lead->id }}">
                            <a class="crm-link" href="{{ route('leads.show', $lead) }}" wire:navigate>{{ $lead->full_name }}</a>
                            <p class="crm-module-description">{{ $lead->phone }} - {{ $lead->assignedUser?->name ?? 'Sin asesor' }}</p>
                            <span class="crm-badge crm-badge--info">{{ $lead->pivot->relation_type }}</span>
                        </article>
                    @empty
                        <div class="crm-empty">Sin leads asociados.</div>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>

    <section class="crm-card crm-panel-pad">
        <div class="crm-propiedades-div-15">
            <div>
                <p class="crm-eyebrow">Proyecto</p>
                <h2 class="crm-section-title">Unidades</h2>
            </div>
            <flux:button icon="plus" wire:click="toggleUnitForm">Nueva unidad</flux:button>
        </div>

        @if ($showUnitForm)
            <form wire:submit="addUnit" class="crm-propiedades-form-04">
                <div class="crm-form-grid">
                    <flux:input wire:model="unitForm.unit_number" label="Numero" required />
                    <flux:input wire:model="unitForm.unit_type" label="Tipo" />
                    <flux:input wire:model="unitForm.tower" label="Torre" />
                    <flux:input wire:model="unitForm.floor" label="Piso" />
                    <flux:input wire:model="unitForm.area_m2" label="Area m2" type="number" step="0.01" />
                    <flux:input wire:model="unitForm.bedrooms" label="Dormitorios" type="number" />
                    <flux:input wire:model="unitForm.bathrooms" label="Banos" type="number" />
                    <flux:input wire:model="unitForm.parking_spaces" label="Cocheras" type="number" />
                    <flux:input wire:model="unitForm.price" label="Precio" type="number" step="0.01" />
                    <flux:select wire:model="unitForm.currency" label="Moneda"><option value="PEN">PEN</option><option value="USD">USD</option></flux:select>
                    <flux:select wire:model="unitForm.status" label="Estado">
                        @foreach (PropertyStatus::cases() as $case)
                            <option value="{{ $case->value }}">{{ $case->label() }}</option>
                        @endforeach
                    </flux:select>
                </div>
                <div class="crm-form-actions">
                    <flux:button type="button" wire:click="$set('showUnitForm', false)">Cancelar</flux:button>
                    <flux:button type="submit" variant="primary">Guardar unidad</flux:button>
                </div>
            </form>
        @endif

        <div class="crm-table-wrap">
            <table class="crm-table">
                <thead><tr><th>Unidad</th><th>Area</th><th>Distribucion</th><th>Precio</th><th>Estado</th><th>Acciones</th></tr></thead>
                <tbody>
                    @forelse ($this->record->units as $unit)
                        <tr wire:key="unit-{{ $unit->id }}">
                            <td>{{ $unit->unit_number }}<br><span class="crm-muted-caption">{{ $unit->tower ?: '-' }} {{ $unit->floor ?: '' }}</span></td>
                            <td>{{ $unit->area_m2 ?? '-' }} m2</td>
                            <td>{{ $unit->bedrooms ?? 0 }} dorm. / {{ $unit->bathrooms ?? 0 }} banos</td>
                            <td>{{ strtoupper($unit->currency) === 'USD' ? 'US$' : 'S/' }} {{ number_format((float) $unit->price, 2) }}</td>
                            <td><span class="crm-badge">{{ PropertyStatus::tryFrom($unit->status)?->label() ?? $unit->status }}</span></td>
                            <td>
                                @if ($unit->status !== PropertyStatus::Inactive->value)
                                    <flux:button size="sm" wire:click="changeUnitStatus({{ $unit->id }}, '{{ PropertyStatus::Inactive->value }}')">Inactivar</flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="crm-empty">Sin unidades registradas.</div></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</section>
