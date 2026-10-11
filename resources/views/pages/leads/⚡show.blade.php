<?php

use App\Enums\FollowUpPriority;
use App\Enums\FollowUpType;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadNote;
use App\Models\Property;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detalle de lead')] class extends Component
{
    public Lead $lead;
    public string $note = '';
    public array $followUp = [];
    public ?int $propertyId = null;
    public string $relationType = 'INTERESTED';

    public function mount(Lead $lead): void
    {
        Gate::authorize('view', $lead);
        $this->lead = $lead;
        $this->resetFollowUp();
    }

    public function resetFollowUp(): void
    {
        $this->followUp = [
            'type' => FollowUpType::Call->value,
            'scheduled_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'priority' => FollowUpPriority::Normal->value,
            'note' => '',
        ];
    }

    public function addNote(): void
    {
        Gate::authorize('notes.create');

        $validated = $this->validate(['note' => ['required', 'string', 'max:1000']]);

        LeadNote::query()->create([
            'lead_id' => $this->lead->id,
            'user_id' => Auth::id(),
            'content' => $validated['note'],
        ]);

        $this->note = '';
        Flux::toast(variant: 'success', text: 'Nota agregada.');
    }

    public function createFollowUp(): void
    {
        Gate::authorize('followups.create');

        $validated = $this->validate([
            'followUp.type' => ['required', Rule::in(array_column(FollowUpType::cases(), 'value'))],
            'followUp.scheduled_at' => ['required', 'date'],
            'followUp.priority' => ['required', Rule::in(array_column(FollowUpPriority::cases(), 'value'))],
            'followUp.note' => ['nullable', 'string', 'max:1000'],
        ])['followUp'];

        FollowUp::query()->create([
            'lead_id' => $this->lead->id,
            'assigned_user_id' => $this->lead->assigned_user_id ?: Auth::id(),
            'type' => $validated['type'],
            'scheduled_at' => $validated['scheduled_at'],
            'priority' => $validated['priority'],
            'note' => $validated['note'] ?: null,
        ]);

        $this->lead->update(['next_follow_up_at' => $validated['scheduled_at']]);
        $this->resetFollowUp();
        Flux::toast(variant: 'success', text: 'Seguimiento programado.');
    }

    public function completeFollowUp(int $followUpId): void
    {
        $followUp = FollowUp::query()->findOrFail($followUpId);
        Gate::authorize('update', $followUp);

        $followUp->update([
            'status' => 'COMPLETED',
            'completed_at' => now(),
            'completed_by_user_id' => Auth::id(),
        ]);
        $this->lead->update(['last_contact_at' => now()]);
        Flux::toast(variant: 'success', text: 'Seguimiento completado.');
    }

    public function attachProperty(): void
    {
        Gate::authorize('properties.assign_lead');

        $validated = $this->validate([
            'propertyId' => ['required', 'integer', 'exists:properties,id'],
            'relationType' => ['required', Rule::in(['INTERESTED', 'SUGGESTED', 'VISITED', 'NEGOTIATING', 'REJECTED'])],
        ]);

        $property = Property::query()->findOrFail((int) $validated['propertyId']);
        Gate::authorize('view', $property);

        $this->lead->properties()->syncWithoutDetaching([
            $property->id => [
                'relation_type' => $validated['relationType'],
                'created_by' => Auth::id(),
                'created_at' => now(),
            ],
        ]);

        $this->propertyId = null;
        Flux::toast(variant: 'success', text: 'Propiedad asociada.');
    }

    #[Computed]
    public function record(): Lead
    {
        return Lead::query()->with([
            'source',
            'pipelineStatus',
            'assignedUser',
            'notes.user',
            'followUps.assignedUser',
            'properties.type',
        ])->findOrFail($this->lead->id);
    }

    #[Computed]
    public function propertyOptions(): \Illuminate\Support\Collection
    {
        return Property::query()
            ->visibleFor(Auth::user())
            ->whereNot('status', 'SOLD')
            ->orderBy('title')
            ->limit(100)
            ->get(['id', 'code', 'title', 'district']);
    }
};
?>

<section class="crm-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Lead</p>
            <h1 class="crm-module-title">{{ $this->record->full_name }}</h1>
            <p class="crm-module-description">{{ $this->record->phone }}{{ $this->record->email ? ' - '.$this->record->email : '' }}</p>
        </div>
        <div class="crm-actions">
            <flux:button :href="route('leads.index')" icon="arrow-left" wire:navigate>Volver</flux:button>
            <flux:button :href="route('pipeline.index')" icon="chart-bar" variant="primary" wire:navigate>Pipeline</flux:button>
        </div>
    </header>

    <div class="crm-grid crm-grid--stats">
        <article class="crm-card crm-stat"><span>Origen</span><strong class="crm-leads-strong-01">{{ $this->record->source?->name ?? '-' }}</strong></article>
        <article class="crm-card crm-stat"><span>Etapa</span><strong class="crm-leads-strong-01">{{ $this->record->pipelineStatus?->name ?? '-' }}</strong></article>
        <article class="crm-card crm-stat"><span>Asesor</span><strong class="crm-leads-strong-01">{{ $this->record->assignedUser?->name ?? 'Sin asignar' }}</strong></article>
        <article class="crm-card crm-stat"><span>Presupuesto</span><strong class="crm-leads-strong-01">{{ $this->record->budgetDisplay() }}</strong></article>
    </div>

    <div class="xl:grid-cols-[1.2fr_0.8fr] crm-grid">
        <section class="crm-card crm-panel-pad crm-form-stack">
            <div>
                <p class="crm-eyebrow">Datos</p>
                <h2 class="crm-section-title">Informacion comercial</h2>
            </div>
            <div class="crm-form-grid crm-leads-div-03">
                <div><span class="crm-leads-span-02">Distrito</span><strong class="crm-leads-strong-02">{{ $this->record->district ?: '-' }}</strong></div>
                <div><span class="crm-leads-span-02">Tipo buscado</span><strong class="crm-leads-strong-02">{{ $this->record->property_type ?: '-' }}</strong></div>
                <div><span class="crm-leads-span-02">Ingreso</span><strong class="crm-leads-strong-02">{{ $this->record->entered_at?->format('d/m/Y H:i') }}</strong></div>
                <div><span class="crm-leads-span-02">Proximo seguimiento</span><strong class="crm-leads-strong-02">{{ $this->record->next_follow_up_at?->format('d/m/Y H:i') ?? '-' }}</strong></div>
            </div>
            <p class="crm-leads-p-02">{{ $this->record->notes ?: 'Sin notas generales.' }}</p>

            <div class="crm-leads-div-04">
                <p class="crm-eyebrow">Propiedades</p>
                <form wire:submit="attachProperty" class="md:grid-cols-[1fr_180px_auto] crm-leads-form-02">
                    <flux:select wire:model="propertyId" label="Asociar propiedad">
                        <option value="">Seleccionar</option>
                        @foreach ($this->propertyOptions as $property)
                            <option value="{{ $property->id }}">{{ $property->code }} - {{ $property->title }}</option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="relationType" label="Relacion">
                        <option value="INTERESTED">Interesado</option>
                        <option value="SUGGESTED">Sugerida</option>
                        <option value="VISITED">Visitada</option>
                        <option value="NEGOTIATING">Negociando</option>
                        <option value="REJECTED">Rechazada</option>
                    </flux:select>
                    <div class="crm-leads-div-05"><flux:button type="submit" variant="primary">Asociar</flux:button></div>
                </form>
                <div class="md:grid-cols-2 crm-leads-div-06">
                    @forelse ($this->record->properties as $property)
                        <article class="crm-inset-card">
                            <a class="crm-link" href="{{ route('properties.show', $property) }}" wire:navigate>{{ $property->title }}</a>
                            <p class="crm-module-description">{{ $property->district }} - {{ $property->priceDisplay() }}</p>
                            <span class="crm-badge crm-badge--info">{{ $property->pivot->relation_type }}</span>
                        </article>
                    @empty
                        <div class="crm-empty md:col-span-2">Sin propiedades asociadas.</div>
                    @endforelse
                </div>
            </div>
        </section>

        <aside class="crm-form-stack">
            <section class="crm-card crm-panel-pad">
                <p class="crm-eyebrow">Notas</p>
                <form wire:submit="addNote" class="crm-leads-form-03">
                    <flux:textarea wire:model="note" label="Nueva nota" />
                    <flux:button type="submit" variant="primary">Agregar nota</flux:button>
                </form>
                <div class="crm-leads-div-07">
                    @forelse ($this->record->notes->sortByDesc('created_at') as $noteItem)
                        <article class="crm-leads-article-02">
                            <p>{{ $noteItem->content }}</p>
                            <span class="crm-muted-caption">{{ $noteItem->user?->name }} - {{ $noteItem->created_at?->format('d/m/Y H:i') }}</span>
                        </article>
                    @empty
                        <div class="crm-empty">Sin notas.</div>
                    @endforelse
                </div>
            </section>

            <section class="crm-card crm-panel-pad">
                <p class="crm-eyebrow">Seguimientos</p>
                <form wire:submit="createFollowUp" class="crm-leads-form-03">
                    <div class="md:grid-cols-2 crm-leads-div-08">
                        <flux:select wire:model="followUp.type" label="Tipo">
                            @foreach (App\Enums\FollowUpType::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="followUp.priority" label="Prioridad">
                            @foreach (App\Enums\FollowUpPriority::cases() as $case)
                                <option value="{{ $case->value }}">{{ $case->label() }}</option>
                            @endforeach
                        </flux:select>
                    </div>
                    <flux:input wire:model="followUp.scheduled_at" label="Fecha" type="datetime-local" />
                    <flux:textarea wire:model="followUp.note" label="Nota" />
                    <flux:button type="submit" variant="primary">Programar</flux:button>
                </form>
                <div class="crm-leads-div-07">
                    @forelse ($this->record->followUps->sortBy('scheduled_at') as $item)
                        <article class="crm-leads-article-02">
                            <div class="crm-leads-div-09">
                                <div><strong>{{ $item->type }}</strong><p>{{ $item->scheduled_at?->format('d/m/Y H:i') }}</p></div>
                                <span class="crm-badge {{ $item->status === 'COMPLETED' ? 'crm-badge--success' : 'crm-badge--warning' }}">{{ $item->statusLabel() }}</span>
                            </div>
                            @if ($item->note)<p class="crm-leads-p-03">{{ $item->note }}</p>@endif
                            @if ($item->status === 'PENDING')
                                <flux:button size="sm" class="crm-leads-flux-button-01" wire:click="completeFollowUp({{ $item->id }})">Completar</flux:button>
                            @endif
                        </article>
                    @empty
                        <div class="crm-empty">Sin seguimientos.</div>
                    @endforelse
                </div>
            </section>
        </aside>
    </div>
</section>
