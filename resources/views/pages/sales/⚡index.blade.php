<?php

use App\Actions\Sales\CreateSaleAction;
use App\Enums\CommissionStatus;
use App\Enums\PropertyOperation;
use App\Enums\PropertyStatus;
use App\Enums\RecordStatus;
use App\Enums\SaleStatus;
use App\Models\Commission;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Sale;
use App\Models\User;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Ventas')] class extends Component
{
    private const PAGE_SIZE = 8;

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public string $assignedUser = '';

    #[Url]
    public string $operationType = '';

    #[Url]
    public int $page = 1;

    public bool $showForm = false;

    public ?int $selectedSaleId = null;

    public array $form = [];

    public function mount(): void
    {
        Gate::authorize('viewAny', Sale::class);
        Auth::user()->loadMissing('role');
        $this->dateFrom = now()->startOfMonth()->toDateString();
        $this->dateTo = now()->toDateString();
        $this->resetForm();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'search', 'dateFrom', 'dateTo', 'assignedUser', 'operationType'], true)) {
            $this->page = 1;
        }
    }

    public function resetFilters(): void
    {
        $this->status = '';
        $this->search = '';
        $this->dateFrom = now()->startOfMonth()->toDateString();
        $this->dateTo = now()->toDateString();
        $this->assignedUser = '';
        $this->operationType = '';
        $this->page = 1;
    }

    public function goToPage(int $page): void
    {
        $this->page = max(1, min($page, $this->totalPages));
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

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
        $this->resetValidation();
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

        $this->closeForm();
        Flux::toast(variant: 'success', text: 'Venta registrada correctamente.');
    }

    public function openSale(int $saleId): void
    {
        $sale = Sale::query()->findOrFail($saleId);
        Gate::authorize('view', $sale);
        $this->selectedSaleId = $sale->id;
    }

    public function closeSale(): void
    {
        $this->selectedSaleId = null;
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

    public function exportSales(): StreamedResponse
    {
        Gate::authorize('viewAny', Sale::class);
        $sales = $this->filteredSalesQuery()->with(['lead', 'property', 'advisor', 'commission'])->latest('sold_at')->limit(5000)->get();

        return response()->streamDownload(function () use ($sales): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['Fecha', 'Codigo', 'Lead', 'Telefono', 'Propiedad', 'Asesor', 'Monto', 'Comision', 'Estado'], ';');

            foreach ($sales as $sale) {
                fputcsv($handle, [
                    $sale->sold_at?->format('d/m/Y H:i'),
                    $sale->codeLabel(),
                    $sale->lead?->full_name,
                    $sale->lead?->phone,
                    $sale->property?->title ?? 'Sin propiedad',
                    $sale->advisor?->name ?? 'Sin asesor',
                    $sale->priceDisplay(),
                    $sale->commission?->amountDisplay() ?? '-',
                    $sale->statusLabel(),
                ], ';');
            }

            fclose($handle);
        }, 'ventas-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    #[Computed]
    public function currentRoleLabel(): string
    {
        return Auth::user()->role?->name ?? 'Asesor';
    }

    #[Computed]
    public function sales(): Collection
    {
        return $this->filteredSalesQuery()
            ->with(['lead', 'property.images', 'advisor', 'commission'])
            ->latest('sold_at')
            ->forPage($this->page, self::PAGE_SIZE)
            ->get();
    }

    #[Computed]
    public function saleCount(): int
    {
        return $this->filteredSalesQuery()->count();
    }

    #[Computed]
    public function totalPages(): int
    {
        return max(1, (int) ceil($this->saleCount / self::PAGE_SIZE));
    }

    #[Computed]
    public function pageLinks(): array
    {
        $lastPage = $this->totalPages;
        $start = max(1, min($this->page - 2, $lastPage - 4));

        return range($start, min($lastPage, $start + 4));
    }

    #[Computed]
    public function metrics(): array
    {
        $period = $this->periodSalesQuery();
        $previousStart = $this->periodStart()->copy()->subDays(max(1, $this->periodStart()->diffInDays($this->periodEnd()) + 1));
        $previousEnd = $this->periodStart()->copy()->subSecond();
        $previous = $this->baseSalesQuery()->whereBetween('sold_at', [$previousStart, $previousEnd])->whereNot('status', SaleStatus::Cancelled->value);
        $sales = (clone $period)->count();
        $previousSales = (clone $previous)->count();
        $amount = (float) (clone $period)->sum('price');
        $previousAmount = (float) (clone $previous)->sum('price');
        $commissions = (float) Commission::query()->visibleFor(Auth::user())->whereHas('sale', fn (Builder $query) => $query->whereBetween('sold_at', [$this->periodStart(), $this->periodEnd()])->whereNot('status', SaleStatus::Cancelled->value))->sum('amount');
        $previousCommissions = (float) Commission::query()->visibleFor(Auth::user())->whereHas('sale', fn (Builder $query) => $query->whereBetween('sold_at', [$previousStart, $previousEnd])->whereNot('status', SaleStatus::Cancelled->value))->sum('amount');
        $pending = (clone $period)->where('status', SaleStatus::Registered->value)->count();
        $leadCount = Lead::query()->visibleFor(Auth::user())->whereBetween('created_at', [$this->periodStart(), $this->periodEnd()])->count();
        $conversion = $leadCount > 0 ? round(($sales / $leadCount) * 100, 1) : 0;
        $previousLeadCount = Lead::query()->visibleFor(Auth::user())->whereBetween('created_at', [$previousStart, $previousEnd])->count();
        $previousConversion = $previousLeadCount > 0 ? round(($previousSales / $previousLeadCount) * 100, 1) : 0;

        return [
            ['label' => 'Ventas del periodo', 'value' => number_format($sales), 'change' => $this->percentageChange($sales, $previousSales), 'detail' => 'vs. periodo anterior', 'icon' => 'bi-bar-chart-fill', 'tone' => 'blue'],
            ['label' => 'Monto vendido', 'value' => 'S/ '.number_format($amount, 0), 'change' => $this->percentageChange($amount, $previousAmount), 'detail' => 'vs. periodo anterior', 'icon' => 'bi-cash-stack', 'tone' => 'green'],
            ['label' => 'Comisiones generadas', 'value' => 'S/ '.number_format($commissions, 0), 'change' => $this->percentageChange($commissions, $previousCommissions), 'detail' => 'vs. periodo anterior', 'icon' => 'bi-percent', 'tone' => 'violet'],
            ['label' => 'Pendientes de aprobación', 'value' => number_format($pending), 'change' => $pending.' activas', 'detail' => 'requieren revisión', 'icon' => 'bi-file-earmark-text', 'tone' => 'orange'],
            ['label' => 'Conversión', 'value' => number_format($conversion, 1).'%', 'change' => ($conversion >= $previousConversion ? '+' : '').number_format($conversion - $previousConversion, 1).' pp', 'detail' => 'vs. periodo anterior', 'icon' => 'bi-funnel', 'tone' => 'cyan'],
        ];
    }

    #[Computed]
    public function advisors(): Collection
    {
        $user = Auth::user();

        return User::query()
            ->where('status', RecordStatus::Active->value)
            ->when($user->isSupervisor(), fn (Builder $query) => $query->where(function (Builder $teamQuery) use ($user): void {
                $teamQuery->whereKey($user->id)->orWhere('supervisor_user_id', $user->id);
            }))
            ->when($user->isAsesor(), fn (Builder $query) => $query->whereKey($user->id))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function leads(): Collection
    {
        return Lead::query()->visibleFor(Auth::user())->where('status', RecordStatus::Active->value)->orderBy('full_name')->limit(150)->get(['id', 'full_name', 'phone', 'assigned_user_id']);
    }

    #[Computed]
    public function properties(): Collection
    {
        return Property::query()->visibleFor(Auth::user())->whereIn('status', [PropertyStatus::Available->value, PropertyStatus::Reserved->value])->orderBy('title')->limit(150)->get(['id', 'code', 'title', 'district', 'price', 'currency', 'operation_type', 'assigned_user_id']);
    }

    #[Computed]
    public function selectedLead(): ?Lead
    {
        return $this->form['lead_id'] ? Lead::query()->visibleFor(Auth::user())->find($this->form['lead_id']) : null;
    }

    #[Computed]
    public function selectedProperty(): ?Property
    {
        return $this->form['property_id'] ? Property::query()->visibleFor(Auth::user())->find($this->form['property_id']) : null;
    }

    #[Computed]
    public function selectedSale(): ?Sale
    {
        if ($this->selectedSaleId === null) {
            return null;
        }

        return $this->baseSalesQuery()->with(['lead', 'property', 'advisor', 'commission'])->find($this->selectedSaleId);
    }

    #[Computed]
    public function recentSales(): Collection
    {
        return $this->baseSalesQuery()->with(['lead', 'advisor', 'commission'])->latest('updated_at')->limit(5)->get();
    }

    #[Computed]
    public function commissionRanking(): Collection
    {
        $commissions = Commission::query()
            ->visibleFor(Auth::user())
            ->whereHas('sale', fn (Builder $query) => $query->whereBetween('sold_at', [$this->periodStart(), $this->periodEnd()])->whereNot('status', SaleStatus::Cancelled->value))
            ->with('advisor:id,name')
            ->get();

        return $commissions->groupBy('advisor_user_id')->map(function (Collection $items): array {
            return ['name' => $items->first()->advisor?->name ?? 'Sin asesor', 'amount' => (float) $items->sum('amount')];
        })->sortByDesc('amount')->values()->take(6);
    }

    #[Computed]
    public function alerts(): array
    {
        $period = $this->periodSalesQuery();
        $pending = (clone $period)->where('status', SaleStatus::Registered->value)->count();
        $commissionPending = Commission::query()->visibleFor(Auth::user())->where('status', CommissionStatus::Pending->value)->whereHas('sale', fn (Builder $query) => $query->whereNot('status', SaleStatus::Cancelled->value))->count();

        return [
            ['label' => 'Ventas pendientes de aprobación', 'description' => 'Requieren revisión administrativa', 'value' => $pending, 'icon' => 'bi-file-earmark-text', 'tone' => 'red'],
            ['label' => 'Comisiones por liquidar', 'description' => 'Ventas con comisión pendiente', 'value' => $commissionPending, 'icon' => 'bi-cash-coin', 'tone' => 'amber'],
            ['label' => 'Ventas en revisión', 'description' => 'Cierres registrados en el periodo', 'value' => (clone $period)->where('status', SaleStatus::Registered->value)->count(), 'icon' => 'bi-pencil-square', 'tone' => 'blue'],
        ];
    }

    public function statusClass(string $status): string
    {
        return match ($status) {
            SaleStatus::Approved->value => 'is-approved',
            SaleStatus::Cancelled->value => 'is-cancelled',
            default => 'is-registered',
        };
    }

    public function avatarTone(int $id): string
    {
        return ['blue', 'green', 'violet', 'orange', 'cyan'][$id % 5];
    }

    public function personInitials(?string $name): string
    {
        return collect(explode(' ', trim((string) $name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->join('');
    }

    public function percentWidth(float $amount): int
    {
        $max = (float) ($this->commissionRanking->max('amount') ?: 1);

        return max(4, min(100, (int) round(($amount / $max) * 100)));
    }

    public function saleDateLabel(?Carbon $date): string
    {
        return $date?->format('d/m/Y') ?? '-';
    }

    private function baseSalesQuery(): Builder
    {
        return Sale::query()->visibleFor(Auth::user());
    }

    private function periodSalesQuery(): Builder
    {
        return $this->baseSalesQuery()->whereBetween('sold_at', [$this->periodStart(), $this->periodEnd()])->whereNot('status', SaleStatus::Cancelled->value);
    }

    private function filteredSalesQuery(): Builder
    {
        return $this->baseSalesQuery()
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->validDate($this->dateFrom) !== null, fn (Builder $query) => $query->whereDate('sold_at', '>=', $this->dateFrom))
            ->when($this->validDate($this->dateTo) !== null, fn (Builder $query) => $query->whereDate('sold_at', '<=', $this->dateTo))
            ->when($this->assignedUser !== '', fn (Builder $query) => $query->where('advisor_user_id', (int) $this->assignedUser))
            ->when($this->operationType !== '', fn (Builder $query) => $query->whereHas('property', fn (Builder $propertyQuery) => $propertyQuery->where('operation_type', $this->operationType)))
            ->when(trim($this->search) !== '', function (Builder $query): void {
                $search = trim($this->search);
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->whereHas('lead', fn (Builder $leadQuery) => $leadQuery->where('full_name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'))
                        ->orWhereHas('property', fn (Builder $propertyQuery) => $propertyQuery->where('title', 'like', '%'.$search.'%')->orWhere('code', 'like', '%'.$search.'%'))
                        ->orWhereHas('advisor', fn (Builder $advisorQuery) => $advisorQuery->where('name', 'like', '%'.$search.'%'));
                });
            });
    }

    private function periodStart(): Carbon
    {
        return Carbon::parse($this->validDate($this->dateFrom) ?? now()->startOfMonth()->toDateString())->startOfDay();
    }

    private function periodEnd(): Carbon
    {
        return Carbon::parse($this->validDate($this->dateTo) ?? now()->toDateString())->endOfDay();
    }

    private function validDate(string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    private function percentageChange(float|int $current, float|int $previous): string
    {
        if ((float) $previous === 0.0) {
            return $current > 0 ? '+100%' : '+0%';
        }

        $change = (($current - $previous) / $previous) * 100;

        return ($change >= 0 ? '+' : '').number_format($change, 1).'%';
    }
};
?>

<section class="crm-page crm-sales-page">
    <header class="crm-page-header crm-sales-page-header">
        <div>
            <p class="crm-eyebrow">Operaciones</p>
            <h1 class="crm-module-title">Ventas</h1>
            <p class="crm-module-description">Gestiona cierres comerciales, montos, comisiones y estado de cada operación.</p>
            <p class="crm-sales-scope"><i class="bi bi-shield-check" aria-hidden="true"></i> {{ $this->currentRoleLabel }} · Datos visibles según tu rol y equipo</p>
        </div>
        <div class="crm-sales-header-actions">
            <label class="crm-sales-header-status"><span>Estado</span><select wire:model.live="status"><option value="">Todas</option>@foreach (SaleStatus::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</select></label>
            <flux:button icon="arrow-down-tray" wire:click="exportSales" wire:loading.attr="disabled" wire:target="exportSales">Exportar</flux:button>
            @can('create', \App\Models\Sale::class)<flux:button icon="plus" variant="primary" wire:click="create">Nueva venta</flux:button>@endcan
        </div>
    </header>

    <section class="crm-sales-stats">
        @foreach ($this->metrics as $metric)
            <article class="crm-card crm-sales-stat crm-sales-stat--{{ $metric['tone'] }}"><span class="crm-sales-stat-icon"><i class="bi {{ $metric['icon'] }}"></i></span><div><p>{{ $metric['label'] }}</p><strong>{{ $metric['value'] }}</strong><small class="{{ str_starts_with($metric['change'], '-') ? 'is-negative' : '' }}">↗ {{ $metric['change'] }} <em>{{ $metric['detail'] }}</em></small></div></article>
        @endforeach
    </section>

    <section class="crm-card crm-sales-filters">
        <div class="crm-sales-filters-heading"><h2><i class="bi bi-search"></i> Filtros de ventas</h2><button type="button" wire:click="resetFilters"><i class="bi bi-arrow-counterclockwise"></i> Limpiar filtros</button></div>
        <div class="crm-sales-filter-grid"><label class="crm-sales-search-field"><span>Buscar venta / lead / propiedad</span><div><i class="bi bi-search"></i><input type="search" wire:model.live.debounce.300ms="search" placeholder="Ej. María López, Torre, VNT-001..."></div></label><label><span>Fecha desde</span><input type="date" wire:model.live="dateFrom"></label><label><span>Fecha hasta</span><input type="date" wire:model.live="dateTo"></label><label><span>Asesor</span><select wire:model.live="assignedUser"><option value="">Todos</option>@foreach ($this->advisors as $advisor)<option value="{{ $advisor->id }}">{{ $advisor->name }}</option>@endforeach</select></label><label><span>Tipo de operación</span><select wire:model.live="operationType"><option value="">Todas</option>@foreach (PropertyOperation::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</select></label><label><span>Estado</span><select wire:model.live="status"><option value="">Todos</option>@foreach (SaleStatus::cases() as $case)<option value="{{ $case->value }}">{{ $case->label() }}</option>@endforeach</select></label><button type="button" class="crm-sales-search-button" wire:click="$refresh"><i class="bi bi-search"></i> Buscar</button></div>
    </section>

    <section class="crm-card crm-sales-table-card">
        <div class="crm-sales-table-heading"><div><h2>Listado de ventas <span>{{ $this->saleCount }}</span></h2><p>Resultados del periodo seleccionado y alcance de tu rol.</p></div><span class="crm-sales-period"><i class="bi bi-calendar3"></i> {{ Carbon::parse($dateFrom)->format('d/m/Y') }} - {{ Carbon::parse($dateTo)->format('d/m/Y') }}</span></div>
        <div class="crm-sales-table-scroll"><table class="crm-sales-table"><thead><tr><th>Fecha</th><th>Código</th><th>Lead</th><th>Propiedad</th><th>Asesor</th><th>Monto</th><th>Comisión</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>@forelse ($this->sales as $sale)<tr wire:key="sale-{{ $sale->id }}"><td><strong>{{ $this->saleDateLabel($sale->sold_at) }}</strong><small>{{ $sale->sold_at?->format('H:i') }}</small></td><td><button type="button" class="crm-sales-code" wire:click="openSale({{ $sale->id }})">{{ $sale->codeLabel() }}</button></td><td><a href="{{ route('leads.show', $sale->lead) }}" wire:navigate><span class="crm-sales-avatar {{ $this->avatarTone($sale->lead_id) }}">{{ $this->personInitials($sale->lead?->full_name) }}</span><span><strong>{{ $sale->lead?->full_name }}</strong><small>{{ $sale->lead?->phone }}</small></span></a></td><td>@if ($sale->property)<a class="crm-sales-property" href="{{ route('properties.show', $sale->property) }}" wire:navigate>@if ($sale->property->images?->first())<img src="{{ Storage::url($sale->property->images->first()->storage_key) }}" alt="">@else<span class="crm-sales-property-placeholder"><i class="bi bi-buildings"></i></span>@endif<span><strong>{{ $sale->property->title }}</strong><small>{{ $sale->property->district }}</small></span></a>@else<span class="crm-sales-muted">Sin propiedad</span>@endif</td><td><span class="crm-sales-advisor"><span class="crm-sales-avatar {{ $this->avatarTone($sale->advisor_user_id ?? 0) }}">{{ $sale->advisor?->initials() }}</span>{{ $sale->advisor?->name ?? 'Sin asesor' }}</span></td><td><strong class="crm-sales-amount">{{ $sale->priceDisplay() }}</strong></td><td>{{ $sale->commission?->amountDisplay() ?? '-' }}</td><td><span class="crm-sales-status {{ $this->statusClass($sale->status) }}"><i class="bi bi-circle-fill"></i>{{ $sale->statusLabel() }}</span></td><td><div class="crm-sales-row-actions"><button type="button" wire:click="openSale({{ $sale->id }})" title="Ver detalle"><i class="bi bi-eye"></i></button>@can('update', $sale) @if ($sale->status === SaleStatus::Registered->value)<button type="button" wire:click="approve({{ $sale->id }})" title="Aprobar"><i class="bi bi-pencil-square"></i></button><button type="button" wire:click="cancel({{ $sale->id }})" wire:confirm="¿Cancelar esta venta?" title="Cancelar"><i class="bi bi-three-dots"></i></button>@endif @endcan</div></td></tr>@empty<tr><td colspan="9"><div class="crm-empty"><i class="bi bi-receipt"></i><strong>No hay ventas con estos filtros.</strong><span>Registra una nueva venta o ajusta el periodo de búsqueda.</span></div></td></tr>@endforelse</tbody></table></div>
        <div class="crm-sales-pagination"><span>Mostrando {{ $this->sales->count() }} de {{ $this->saleCount }} ventas</span><div><button type="button" wire:click="goToPage({{ max(1, $page - 1) }})" @disabled($page <= 1)><i class="bi bi-chevron-left"></i></button>@foreach ($this->pageLinks as $link)<button type="button" class="{{ $link === $page ? 'is-active' : '' }}" wire:click="goToPage({{ $link }})">{{ $link }}</button>@endforeach<button type="button" wire:click="goToPage({{ min($this->totalPages, $page + 1) }})" @disabled($page >= $this->totalPages)><i class="bi bi-chevron-right"></i></button></div></div>
    </section>

    <section class="crm-sales-bottom-grid"><section class="crm-card crm-sales-bottom-card"><div class="crm-sales-bottom-heading"><div><h2><i class="bi bi-clock-history"></i> Actividad reciente</h2><p>Últimas operaciones registradas.</p></div><a href="#" wire:click.prevent="$refresh">Ver todas <i class="bi bi-arrow-right"></i></a></div><div class="crm-sales-activity-list">@forelse ($this->recentSales as $sale)<article><span class="crm-sales-activity-icon is-green"><i class="bi bi-cash-stack"></i></span><div><strong>{{ $sale->codeLabel() }} {{ $sale->status === SaleStatus::Approved->value ? 'aprobada' : 'registrada' }}</strong><small>{{ $sale->advisor?->name ?? 'Sin asesor' }} · {{ $sale->updated_at?->diffForHumans() }}</small></div><span class="crm-sales-activity-amount">{{ $sale->priceDisplay() }}</span></article>@empty<div class="crm-sales-mini-empty">No hay actividad reciente.</div>@endforelse</div></section><section class="crm-card crm-sales-bottom-card"><div class="crm-sales-bottom-heading"><div><h2><i class="bi bi-bell"></i> Alertas y pendientes</h2><p>Operaciones que requieren atención.</p></div><a href="#" wire:click.prevent="$refresh">Ver todas</a></div><div class="crm-sales-alert-list">@foreach ($this->alerts as $alert)<article class="crm-sales-alert crm-sales-alert--{{ $alert['tone'] }}"><span><i class="bi {{ $alert['icon'] }}"></i></span><div><strong>{{ $alert['value'] }} {{ $alert['label'] }}</strong><small>{{ $alert['description'] }}</small></div><i class="bi bi-chevron-right"></i></article>@endforeach</div></section><section class="crm-card crm-sales-bottom-card"><div class="crm-sales-bottom-heading"><div><h2><i class="bi bi-percent"></i> Comisiones por asesor</h2><p>Distribución del periodo seleccionado.</p></div></div><div class="crm-sales-commission-list">@forelse ($this->commissionRanking as $index => $item)<article><span class="crm-sales-avatar {{ $this->avatarTone($index) }}">{{ collect(explode(' ', $item['name']))->map(fn ($part) => mb_substr($part, 0, 1))->join('') }}</span><div><strong>{{ $item['name'] }}</strong><small>S/ {{ number_format($item['amount'], 0) }}</small></div><span class="crm-sales-commission-track"><i style="width: {{ $this->percentWidth($item['amount']) }}%"></i></span></article>@empty<div class="crm-sales-mini-empty">No hay comisiones en este periodo.</div>@endforelse</div></section></section>

    @if ($showForm)
        <div class="crm-sales-modal-layer" wire:key="sale-form-modal"><div class="crm-sales-modal-backdrop" wire:click="closeForm"></div><section class="crm-card crm-sales-form-modal" role="dialog" aria-modal="true" aria-labelledby="sale-form-title"><div class="crm-sales-form-heading"><div><p class="crm-eyebrow">Cierre comercial</p><h2 id="sale-form-title">Nueva venta</h2><p>Registra el cierre, propiedad, asesor y comisión desde el mismo panel.</p></div><button type="button" wire:click="closeForm" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button></div><form wire:submit="save"><div class="crm-sales-form-sections"><section><div class="crm-sales-form-section-heading"><span><i class="bi bi-person-check"></i></span><div><h3>Lead y responsable</h3><p>Asocia la venta al cliente y al asesor correspondiente.</p></div></div><div class="crm-sales-form-grid"><label><span>Lead <em>*</em></span><select wire:model="form.lead_id"><option value="">Selecciona un lead</option>@foreach ($this->leads as $lead)<option value="{{ $lead->id }}">{{ $lead->full_name }} · {{ $lead->phone }}</option>@endforeach</select>@error('form.lead_id')<small>{{ $message }}</small>@enderror</label><label><span>Asesor responsable</span><select wire:model="form.advisor_user_id"><option value="">Usar asesor del lead</option>@foreach ($this->advisors as $advisor)<option value="{{ $advisor->id }}">{{ $advisor->name }}</option>@endforeach</select></label></div></section><section><div class="crm-sales-form-section-heading"><span><i class="bi bi-buildings"></i></span><div><h3>Propiedad y monto</h3><p>Selecciona el inmueble vendido y confirma el importe.</p></div></div><div class="crm-sales-form-grid"><label class="crm-sales-form-wide"><span>Propiedad</span><select wire:model="form.property_id"><option value="">Venta sin propiedad</option>@foreach ($this->properties as $property)<option value="{{ $property->id }}">{{ $property->code }} · {{ $property->title }} · {{ $property->district }}</option>@endforeach</select></label><label><span>Precio de venta</span><input type="number" step="0.01" wire:model="form.price" placeholder="Se usará el precio de la propiedad">@error('form.price')<small>{{ $message }}</small>@enderror</label><label><span>Moneda</span><select wire:model="form.currency"><option value="PEN">PEN - Soles</option><option value="USD">USD - Dólares</option></select></label><label><span>Fecha de venta <em>*</em></span><input type="datetime-local" wire:model="form.sold_at">@error('form.sold_at')<small>{{ $message }}</small>@enderror</label><label><span>% Comisión <em>*</em></span><input type="number" step="0.001" min="0" max="100" wire:model="form.commission_percentage"></label></div>@if ($this->selectedProperty)<div class="crm-sales-selected-property"><span class="crm-sales-property-placeholder"><i class="bi bi-buildings"></i></span><div><strong>{{ $this->selectedProperty->title }}</strong><small>{{ $this->selectedProperty->code }} · {{ $this->selectedProperty->district }} · {{ $this->selectedProperty->priceDisplay() }}</small></div><i class="bi bi-check-circle-fill"></i></div>@endif</section><section><div class="crm-sales-form-section-heading"><span><i class="bi bi-file-text"></i></span><div><h3>Documentación y notas</h3><p>Añade información complementaria del cierre.</p></div></div><label class="crm-sales-form-note"><span>Notas</span><textarea wire:model="form.notes" rows="3" placeholder="Contrato, observaciones o condiciones especiales..."></textarea></label></section></div><div class="crm-sales-form-actions"><button type="button" class="crm-sales-secondary-button" wire:click="closeForm">Cancelar</button><button type="submit" class="crm-sales-primary-button" wire:loading.attr="disabled"><i class="bi bi-check2"></i> Registrar venta</button></div></form></section></div>
    @endif

    @if ($this->selectedSale)
        <div class="crm-sales-modal-layer" wire:key="sale-detail-modal"><div class="crm-sales-modal-backdrop" wire:click="closeSale"></div><section class="crm-card crm-sales-detail-modal" role="dialog" aria-modal="true"><div class="crm-sales-form-heading"><div><p class="crm-eyebrow">Detalle de operación</p><h2>{{ $this->selectedSale->codeLabel() }}</h2><p>{{ $this->selectedSale->statusLabel() }} · {{ $this->selectedSale->sold_at?->format('d/m/Y H:i') }}</p></div><button type="button" wire:click="closeSale" aria-label="Cerrar"><i class="bi bi-x-lg"></i></button></div><div class="crm-sales-detail-grid"><div><span>Lead</span><strong>{{ $this->selectedSale->lead?->full_name }}</strong><small>{{ $this->selectedSale->lead?->phone }}</small></div><div><span>Propiedad</span><strong>{{ $this->selectedSale->property?->title ?? 'Sin propiedad' }}</strong><small>{{ $this->selectedSale->property?->district }}</small></div><div><span>Asesor</span><strong>{{ $this->selectedSale->advisor?->name ?? 'Sin asesor' }}</strong></div><div><span>Monto vendido</span><strong>{{ $this->selectedSale->priceDisplay() }}</strong></div><div><span>Comisión</span><strong>{{ $this->selectedSale->commission?->amountDisplay() ?? '-' }}</strong></div><div><span>Estado</span><strong class="crm-sales-status {{ $this->statusClass($this->selectedSale->status) }}">{{ $this->selectedSale->statusLabel() }}</strong></div></div>@if ($this->selectedSale->notes)<div class="crm-sales-detail-notes"><span>Notas</span><p>{{ $this->selectedSale->notes }}</p></div>@endif</section></div>
    @endif
</section>
