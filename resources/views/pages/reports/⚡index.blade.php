<?php

use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Enums\ReservationStatus;
use App\Enums\SaleStatus;
use App\Models\Commission;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Property;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\User;
use App\Support\Reports\ReportDocument;
use App\Support\Reports\ReportExporter;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Title('Reportes')] class extends Component
{
    public string $from = '';

    public string $to = '';

    public bool $showReportBuilder = false;

    public bool $reportReady = false;

    public string $advisorSearch = '';

    public string $reportType = 'resumen';

    public string $activeReportType = 'resumen';

    public string $groupBy = 'monthly';

    public function mount(): void
    {
        Gate::authorize('reports.view');
        $this->from = now()->startOfMonth()->format('Y-m-d');
        $this->to = now()->format('Y-m-d');
    }

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'reportType' => ['required', Rule::enum(ReportType::class)],
            'groupBy' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'from.required' => 'Selecciona la fecha inicial.',
            'to.required' => 'Selecciona la fecha final.',
            'from.date_format' => 'La fecha inicial no es valida.',
            'to.date_format' => 'La fecha final no es valida.',
            'to.after_or_equal' => 'La fecha final debe ser igual o posterior a la inicial.',
        ];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'to', 'reportType', 'groupBy'], true)) {
            $this->reportReady = false;
        }

        if (in_array($property, ['from', 'to'], true)) {
            $this->validate();
        }
    }

    public function openReportBuilder(): void
    {
        Gate::authorize('reports.view');
        $this->showReportBuilder = true;
        $this->reportReady = false;
    }

    public function closeReportBuilder(): void
    {
        $this->showReportBuilder = false;
        $this->reportReady = false;
    }

    public function toggleReportBuilder(): void
    {
        Gate::authorize('reports.view');

        if ($this->showReportBuilder) {
            $this->showDashboard();

            return;
        }

        $this->openReportBuilder();
    }

    public function setPeriod(string $period): void
    {
        Gate::authorize('reports.view');

        $today = CarbonImmutable::today();

        [$from, $to] = match ($period) {
            'today' => [$today, $today],
            'week' => [$today->startOfWeek(), $today],
            'month' => [$today->startOfMonth(), $today],
            'quarter' => [$today->startOfMonth()->subMonths(2), $today],
            'semester' => [$today->startOfMonth()->subMonths(5), $today],
            'year' => [$today->startOfYear(), $today],
            'all' => [$this->earliestReportDate(Auth::user()), $today],
            default => [$today->startOfMonth(), $today],
        };

        $this->from = $from->format('Y-m-d');
        $this->to = $to->format('Y-m-d');
        $this->reportReady = false;
        $this->resetValidation(['from', 'to']);
    }

    public function generateReport(): void
    {
        Gate::authorize('reports.view');
        $this->validate();
        $this->activeReportType = $this->reportType;
        $this->reportReady = true;
    }

    public function showDashboard(): void
    {
        Gate::authorize('reports.view');
        $this->showReportBuilder = false;
        $this->reportReady = false;
    }

    public function exportReport(string $report, string $format): StreamedResponse
    {
        Gate::authorize('reports.view');
        $this->validate();
        Validator::make(compact('report', 'format'), [
            'report' => ['required', Rule::enum(ReportType::class)],
            'format' => ['required', Rule::enum(ReportFormat::class)],
        ])->validate();

        $type = ReportType::from($report);
        $this->activeReportType = $type->value;
        $user = Auth::user();
        $rows = match ($type) {
            ReportType::Summary => [
                ['Leads nuevos', $this->stats['leads'], 'Registros'],
                ['Propiedades visibles', $this->stats['properties'], 'Registros'],
                ['Seguimientos pendientes', $this->stats['followups_pending'], 'Registros'],
                ['Ventas', $this->stats['sales'], 'Registros'],
                ['Monto vendido', (float) $this->stats['sales_amount'], 'PEN'],
                ['Comisiones pendientes', (float) $this->stats['commissions_pending'], 'PEN'],
            ],
            ReportType::Pipeline => $this->pipeline->map(fn (array $item): array => [$item['name'], $item['count']])->all(),
            ReportType::Sources => $this->sources->map(fn (array $item): array => [$item['name'], $item['count']])->all(),
            default => $this->reportRows,
        };

        return app(ReportExporter::class)->download(new ReportDocument(
            type: $type,
            rows: $rows,
            from: $this->from,
            to: $this->to,
            scope: match (true) {
                $user->isAdministrador() => 'Todos los registros',
                $user->isSupervisor() => 'Equipo a cargo',
                default => 'Mis registros',
            },
            author: $user->name,
            generatedAt: CarbonImmutable::now('America/Lima'),
        ), ReportFormat::from($format));
    }

    #[Computed]
    public function stats(): array
    {
        $from = $this->from.' 00:00:00';
        $to = $this->to.' 23:59:59';
        $user = Auth::user();

        return [
            'leads' => Lead::query()->visibleFor($user)->whereBetween('entered_at', [$from, $to])->count(),
            'properties' => Property::query()->visibleFor($user)->count(),
            'followups_pending' => FollowUp::query()->visibleFor($user)->where('status', 'PENDING')->count(),
            'sales' => Sale::query()->visibleFor($user)->whereBetween('sold_at', [$from, $to])->whereNot('status', SaleStatus::Cancelled->value)->count(),
            'sales_amount' => Sale::query()->visibleFor($user)->whereBetween('sold_at', [$from, $to])->whereNot('status', SaleStatus::Cancelled->value)->sum('price'),
            'commissions_pending' => Commission::query()->visibleFor($user)->where('status', 'PENDING')->sum('amount'),
            'reservations_active' => Reservation::query()->visibleFor($user)
                ->where('status', ReservationStatus::Active->value)
                ->whereBetween('reserved_at', [$from, $to])
                ->count(),
            'sales_closed' => Sale::query()->visibleFor($user)
                ->whereBetween('sold_at', [$from, $to])
                ->whereNot('status', SaleStatus::Cancelled->value)
                ->count(),
            'commissions_generated' => Commission::query()->visibleFor($user)
                ->whereHas('sale', fn (Builder $query): Builder => $query
                    ->whereBetween('sold_at', [$from, $to])
                    ->whereNot('status', SaleStatus::Cancelled->value))
                ->sum('amount'),
            'conversion' => $this->conversionRate(
                Lead::query()->visibleFor($user)->whereBetween('entered_at', [$from, $to])->count(),
                Sale::query()->visibleFor($user)->whereBetween('sold_at', [$from, $to])->whereNot('status', SaleStatus::Cancelled->value)->count(),
            ),
        ];
    }

    #[Computed]
    public function dashboardStats(): array
    {
        $current = $this->periodMetrics($this->dateRange());
        $currentFrom = CarbonImmutable::parse($this->from)->startOfDay();
        $currentTo = CarbonImmutable::parse($this->to)->endOfDay();
        $days = $currentFrom->diffInDays($currentTo) + 1;
        $previousTo = $currentFrom->subDay()->endOfDay();
        $previousFrom = $previousTo->subDays($days - 1)->startOfDay();
        $previous = $this->periodMetrics([$previousFrom->format('Y-m-d H:i:s'), $previousTo->format('Y-m-d H:i:s')]);

        return [
            ...$current,
            'changes' => collect($current)
                ->mapWithKeys(fn (int|float $value, string $key): array => [$key => $this->percentageChange($value, $previous[$key])])
                ->all(),
        ];
    }

    #[Computed]
    public function salesEvolution(): \Illuminate\Support\Collection
    {
        $from = CarbonImmutable::parse($this->from)->startOfDay();
        $to = CarbonImmutable::parse($this->to)->endOfDay();
        $bucketType = $this->groupBy;
        $cursor = match ($bucketType) {
            'weekly' => $from->startOfWeek(),
            'monthly' => $from->startOfMonth(),
            default => $from,
        };
        $buckets = [];

        while ($cursor->lessThanOrEqualTo($to)) {
            $key = match ($bucketType) {
                'weekly' => $cursor->format('o-W'),
                'monthly' => $cursor->format('Y-m'),
                default => $cursor->format('Y-m-d'),
            };
            $buckets[$key] = [
                'label' => match ($bucketType) {
                    'weekly' => 'Sem '.$cursor->format('d/m'),
                    'monthly' => ucfirst($cursor->locale('es')->isoFormat('MMM YY')),
                    default => $cursor->format('d M'),
                },
                'sales' => 0,
                'amount' => 0.0,
            ];
            $cursor = match ($bucketType) {
                'weekly' => $cursor->addWeek(),
                'monthly' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        Sale::query()
            ->visibleFor(Auth::user())
            ->whereBetween('sold_at', [$from, $to])
            ->whereNot('status', SaleStatus::Cancelled->value)
            ->get(['sold_at', 'price'])
            ->each(function (Sale $sale) use (&$buckets, $bucketType): void {
                $date = CarbonImmutable::parse($sale->sold_at);
                $key = match ($bucketType) {
                    'weekly' => $date->startOfWeek()->format('o-W'),
                    'monthly' => $date->format('Y-m'),
                    default => $date->format('Y-m-d'),
                };

                if (isset($buckets[$key])) {
                    $buckets[$key]['sales']++;
                    $buckets[$key]['amount'] += (float) $sale->price;
                }
            });

        return collect(array_values($buckets))->take(-12)->values();
    }

    #[Computed]
    public function advisorPerformance(): \Illuminate\Support\Collection
    {
        $user = Auth::user();
        $users = $this->reportUsers();
        $range = $this->dateRange();
        $sales = Sale::query()
            ->visibleFor($user)
            ->whereBetween('sold_at', $range)
            ->whereNot('status', SaleStatus::Cancelled->value)
            ->selectRaw('advisor_user_id, COUNT(*) as sales_count, SUM(price) as sales_amount')
            ->groupBy('advisor_user_id')
            ->get()
            ->keyBy('advisor_user_id');
        $leads = Lead::query()
            ->visibleFor($user)
            ->whereBetween('entered_at', $range)
            ->selectRaw('assigned_user_id, COUNT(*) as lead_count')
            ->groupBy('assigned_user_id')
            ->get()
            ->keyBy('assigned_user_id');
        $reservations = Reservation::query()
            ->visibleFor($user)
            ->whereBetween('reserved_at', $range)
            ->selectRaw('user_id, COUNT(*) as reservation_count')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
        $commissions = Commission::query()
            ->visibleFor($user)
            ->whereHas('sale', fn (Builder $query): Builder => $query
                ->whereBetween('sold_at', $range)
                ->whereNot('status', SaleStatus::Cancelled->value))
            ->selectRaw('advisor_user_id, SUM(amount) as commission_amount')
            ->groupBy('advisor_user_id')
            ->get()
            ->keyBy('advisor_user_id');

        return $users
            ->map(function (User $advisor) use ($sales, $leads, $reservations, $commissions): array {
                $sale = $sales->get($advisor->id);
                $leadCount = (int) ($leads->get($advisor->id)?->lead_count ?? 0);
                $salesCount = (int) ($sale?->sales_count ?? 0);

                return [
                    'id' => $advisor->id,
                    'name' => $advisor->name,
                    'initials' => $advisor->initials(),
                    'leads' => $leadCount,
                    'reservations' => (int) ($reservations->get($advisor->id)?->reservation_count ?? 0),
                    'sales' => $salesCount,
                    'sales_amount' => (float) ($sale?->sales_amount ?? 0),
                    'conversion' => $this->conversionRate($leadCount, $salesCount),
                    'commission' => (float) ($commissions->get($advisor->id)?->commission_amount ?? 0),
                    'status' => $advisor->status,
                ];
            })
            ->filter(fn (array $advisor): bool => $this->advisorSearch === '' || str_contains(strtolower($advisor['name']), strtolower($this->advisorSearch)))
            ->values();
    }

    /** @return array<string, string> */
    public function reportRoleCopy(): array
    {
        $user = Auth::user();

        return match (true) {
            $user->isAdministrador() => [
                'title' => 'Resumen general',
                'description' => 'Indicadores clave de tu desempeno comercial y administrativo.',
                'scope' => 'Vista global del negocio',
            ],
            $user->isSupervisor() => [
                'title' => 'Resumen de mi equipo',
                'description' => 'Supervisa el avance comercial y el rendimiento de tus asesores.',
                'scope' => 'Equipo a cargo',
            ],
            default => [
                'title' => 'Mi desempeno',
                'description' => 'Consulta tus oportunidades, reservas, ventas y comisiones.',
                'scope' => 'Mis registros',
            ],
        };
    }

    #[Computed]
    public function pipeline(): \Illuminate\Support\Collection
    {
        return LeadStatus::query()
            ->where('status', 'active')
            ->withCount(['leads' => fn (Builder $query): Builder => $query
                ->visibleFor(Auth::user())
                ->whereBetween('entered_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])])
            ->orderBy('sort_order')
            ->get()
            ->map(function (LeadStatus $status): array {
                return [
                    'name' => $status->name,
                    'color' => $status->color,
                    'count' => $status->leads_count,
                ];
            });
    }

    #[Computed]
    public function sources(): \Illuminate\Support\Collection
    {
        return LeadSource::query()
            ->where('status', 'active')
            ->withCount(['leads' => fn (Builder $query): Builder => $query
                ->visibleFor(Auth::user())
                ->whereBetween('entered_at', [$this->from.' 00:00:00', $this->to.' 23:59:59'])])
            ->orderBy('name')
            ->get()
            ->map(function (LeadSource $source): array {
                return [
                    'name' => $source->name,
                    'count' => $source->leads_count,
                ];
            })
            ->filter(fn (array $item): bool => $item['count'] > 0)
            ->values();
    }

    #[Computed]
    public function selectedReport(): ReportType
    {
        return ReportType::from($this->activeReportType);
    }

    /** @return list<list<string|int|float>> */
    #[Computed]
    public function reportRows(): array
    {
        return match ($this->selectedReport) {
            ReportType::Summary => [
                ['Leads nuevos', $this->stats['leads'], 'Registros'],
                ['Propiedades visibles', $this->stats['properties'], 'Registros'],
                ['Seguimientos pendientes', $this->stats['followups_pending'], 'Registros'],
                ['Ventas', $this->stats['sales'], 'Registros'],
                ['Monto vendido', (float) $this->stats['sales_amount'], 'PEN'],
                ['Comisiones pendientes', (float) $this->stats['commissions_pending'], 'PEN'],
            ],
            ReportType::Pipeline => $this->pipeline->map(fn (array $item): array => [$item['name'], $item['count']])->all(),
            ReportType::Sources => $this->sources->map(fn (array $item): array => [$item['name'], $item['count']])->all(),
            ReportType::Sales => $this->salesReportRows(),
            ReportType::Commissions => $this->commissionReportRows(),
            ReportType::Properties => $this->propertyReportRows(),
            ReportType::SoldProperties => $this->soldPropertyReportRows(),
            ReportType::Leads => $this->leadReportRows(),
            ReportType::FollowUps => $this->followUpReportRows(),
        };
    }

    #[Computed]
    public function timeline(): \Illuminate\Support\Collection
    {
        if (! $this->reportReady) {
            return collect();
        }

        $buckets = $this->timelineBuckets();
        $range = $this->dateRange();
        $user = Auth::user();
        $addEvent = function (CarbonInterface|string $date, string $metric, float $amount = 1) use (&$buckets): void {
            $key = $this->timelineKey(CarbonImmutable::parse($date));

            if (isset($buckets[$key])) {
                $buckets[$key][$metric] += $amount;
            }
        };

        Lead::query()
            ->visibleFor($user)
            ->whereBetween('entered_at', $range)
            ->get(['entered_at'])
            ->each(function (Lead $lead) use ($addEvent): void {
                $addEvent($lead->entered_at, 'leads');
            });

        Property::query()
            ->visibleFor($user)
            ->whereBetween('created_at', $range)
            ->get(['created_at'])
            ->each(function (Property $property) use ($addEvent): void {
                $addEvent($property->created_at, 'properties');
            });

        Sale::query()
            ->visibleFor($user)
            ->whereBetween('sold_at', $range)
            ->whereNot('status', SaleStatus::Cancelled->value)
            ->get(['sold_at', 'price', 'property_id'])
            ->each(function (Sale $sale) use ($addEvent): void {
                $addEvent($sale->sold_at, 'sales');
                $addEvent($sale->sold_at, 'sales_amount', (float) $sale->price);

                if ($sale->property_id !== null) {
                    $addEvent($sale->sold_at, 'sold_properties');
                }
            });

        Commission::query()
            ->visibleFor($user)
            ->whereHas('sale', function (Builder $query) use ($range): void {
                $query
                    ->whereBetween('sold_at', $range)
                    ->whereNot('status', SaleStatus::Cancelled->value);
            })
            ->with('sale')
            ->get()
            ->each(function (Commission $commission) use ($addEvent): void {
                if ($commission->sale?->sold_at !== null) {
                    $addEvent($commission->sale->sold_at, 'commissions', (float) $commission->amount);
                }
            });

        FollowUp::query()
            ->visibleFor($user)
            ->whereBetween('scheduled_at', $range)
            ->get(['scheduled_at'])
            ->each(function (FollowUp $followUp) use ($addEvent): void {
                $addEvent($followUp->scheduled_at, 'followups');
            });

        return collect(array_values($buckets));
    }

    /** @return list<list<string|int|float>> */
    private function salesReportRows(): array
    {
        return Sale::query()
            ->visibleFor(Auth::user())
            ->with(['lead', 'property', 'advisor'])
            ->whereBetween('sold_at', $this->dateRange())
            ->whereNot('status', SaleStatus::Cancelled->value)
            ->latest('sold_at')
            ->limit(500)
            ->get()
            ->map(fn (Sale $sale): array => [
                $sale->sold_at?->format('d/m/Y H:i') ?? '-',
                $sale->lead?->full_name ?? '-',
                $sale->property?->code ?? '-',
                $sale->property?->title ?? 'Sin propiedad',
                $sale->advisor?->name ?? '-',
                (float) $sale->price,
                $sale->currency,
                $sale->statusLabel(),
            ])->all();
    }

    /** @return list<list<string|int|float>> */
    private function commissionReportRows(): array
    {
        return Commission::query()
            ->visibleFor(Auth::user())
            ->with(['sale.property', 'advisor'])
            ->whereHas('sale', function (Builder $query): void {
                $query
                    ->whereBetween('sold_at', $this->dateRange())
                    ->whereNot('status', SaleStatus::Cancelled->value);
            })
            ->latest()
            ->limit(500)
            ->get()
            ->map(fn (Commission $commission): array => [
                $commission->sale?->sold_at?->format('d/m/Y H:i') ?? '-',
                $commission->sale_id,
                $commission->sale?->property?->title ?? 'Sin propiedad',
                $commission->advisor?->name ?? '-',
                (float) $commission->percentage,
                (float) $commission->amount,
                $commission->currency,
                $commission->statusLabel(),
            ])->all();
    }

    /** @return list<list<string|int|float>> */
    private function propertyReportRows(): array
    {
        return Property::query()
            ->visibleFor(Auth::user())
            ->with(['type', 'assignedUser'])
            ->whereBetween('created_at', $this->dateRange())
            ->latest()
            ->limit(500)
            ->get()
            ->map(fn (Property $property): array => [
                $property->code,
                $property->title,
                $property->type?->name ?? '-',
                $property->district,
                $property->operationLabel(),
                (float) $property->price,
                $property->currency,
                $property->statusLabel(),
                $property->assignedUser?->name ?? '-',
            ])->all();
    }

    /** @return list<list<string|int|float>> */
    private function soldPropertyReportRows(): array
    {
        return Sale::query()
            ->visibleFor(Auth::user())
            ->with(['property.type', 'advisor'])
            ->whereNotNull('property_id')
            ->whereBetween('sold_at', $this->dateRange())
            ->whereNot('status', SaleStatus::Cancelled->value)
            ->latest('sold_at')
            ->limit(500)
            ->get()
            ->map(fn (Sale $sale): array => [
                $sale->sold_at?->format('d/m/Y H:i') ?? '-',
                $sale->property?->code ?? '-',
                $sale->property?->title ?? '-',
                $sale->property?->type?->name ?? '-',
                $sale->property?->district ?? '-',
                (float) $sale->price,
                $sale->currency,
                $sale->advisor?->name ?? '-',
            ])->all();
    }

    /** @return list<list<string|int|float>> */
    private function leadReportRows(): array
    {
        return Lead::query()
            ->visibleFor(Auth::user())
            ->with(['source', 'pipelineStatus', 'assignedUser'])
            ->whereBetween('entered_at', $this->dateRange())
            ->latest('entered_at')
            ->limit(500)
            ->get()
            ->map(fn (Lead $lead): array => [
                $lead->entered_at?->format('d/m/Y H:i') ?? '-',
                $lead->full_name,
                $lead->source?->name ?? '-',
                $lead->pipelineStatus?->name ?? '-',
                $lead->district ?? '-',
                $lead->assignedUser?->name ?? '-',
            ])->all();
    }

    /** @return list<list<string|int|float>> */
    private function followUpReportRows(): array
    {
        return FollowUp::query()
            ->visibleFor(Auth::user())
            ->with(['lead', 'assignedUser'])
            ->whereBetween('scheduled_at', $this->dateRange())
            ->latest('scheduled_at')
            ->limit(500)
            ->get()
            ->map(fn (FollowUp $followUp): array => [
                $followUp->scheduled_at?->format('d/m/Y H:i') ?? '-',
                $followUp->lead?->full_name ?? '-',
                $followUp->type,
                $followUp->priorityLabel(),
                $followUp->statusLabel(),
                $followUp->assignedUser?->name ?? '-',
            ])->all();
    }

    /** @return array{0: string, 1: string} */
    private function dateRange(): array
    {
        return [$this->from.' 00:00:00', $this->to.' 23:59:59'];
    }

    private function earliestReportDate(User $user): CarbonImmutable
    {
        $dates = collect([
            Lead::query()->visibleFor($user)->min('entered_at'),
            Property::query()->visibleFor($user)->min('created_at'),
            Sale::query()->visibleFor($user)->min('sold_at'),
            Reservation::query()->visibleFor($user)->min('reserved_at'),
            FollowUp::query()->visibleFor($user)->min('scheduled_at'),
        ])
            ->filter()
            ->map(fn (mixed $date): CarbonImmutable => CarbonImmutable::parse((string) $date))
            ->sortBy(fn (CarbonImmutable $date): int => $date->getTimestamp());

        return $dates->first() ?? CarbonImmutable::today();
    }

    /** @param array{0: string, 1: string} $range */
    private function periodMetrics(array $range): array
    {
        $user = Auth::user();
        $leadCount = Lead::query()->visibleFor($user)->whereBetween('entered_at', $range)->count();
        $salesCount = Sale::query()
            ->visibleFor($user)
            ->whereBetween('sold_at', $range)
            ->whereNot('status', SaleStatus::Cancelled->value)
            ->count();

        return [
            'leads' => $leadCount,
            'reservations' => Reservation::query()
                ->visibleFor($user)
                ->whereBetween('reserved_at', $range)
                ->where('status', ReservationStatus::Active->value)
                ->count(),
            'sales' => $salesCount,
            'sales_amount' => (float) Sale::query()
                ->visibleFor($user)
                ->whereBetween('sold_at', $range)
                ->whereNot('status', SaleStatus::Cancelled->value)
                ->sum('price'),
            'conversion' => $this->conversionRate($leadCount, $salesCount),
            'commissions' => (float) Commission::query()
                ->visibleFor($user)
                ->whereHas('sale', fn (Builder $query): Builder => $query
                    ->whereBetween('sold_at', $range)
                    ->whereNot('status', SaleStatus::Cancelled->value))
                ->sum('amount'),
        ];
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    private function reportUsers(): \Illuminate\Support\Collection
    {
        $user = Auth::user();

        return User::query()
            ->where('status', 'active')
            ->when($user->isAdministrador(), fn (Builder $query): Builder => $query->whereHas('role', fn (Builder $query): Builder => $query->where('slug', 'asesor')))
            ->when($user->isSupervisor(), fn (Builder $query): Builder => $query->where(function (Builder $query) use ($user): void {
                $query->whereKey($user->id)->orWhere('supervisor_user_id', $user->id);
            }))
            ->when($user->isAsesor(), fn (Builder $query): Builder => $query->whereKey($user->id))
            ->orderBy('name')
            ->get(['id', 'name', 'status']);
    }

    private function conversionRate(int $leads, int $sales): float
    {
        return $leads > 0 ? round(($sales / $leads) * 100, 1) : 0.0;
    }

    private function percentageChange(int|float $current, int|float $previous): float
    {
        if ((float) $previous === 0.0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /** @return array<string, array{label: string, leads: int, properties: int, sales: int, sold_properties: int, sales_amount: float, commissions: float, followups: int}> */
    private function timelineBuckets(): array
    {
        $start = CarbonImmutable::parse($this->from)->startOfDay();
        $end = CarbonImmutable::parse($this->to)->endOfDay();
        $cursor = match ($this->groupBy) {
            'weekly' => $start->startOfWeek(),
            'monthly' => $start->startOfMonth(),
            default => $start,
        };
        $buckets = [];

        while ($cursor->lessThanOrEqualTo($end)) {
            $key = $this->timelineKey($cursor);
            $buckets[$key] = [
                'label' => $this->timelineLabel($cursor),
                'leads' => 0,
                'properties' => 0,
                'sales' => 0,
                'sold_properties' => 0,
                'sales_amount' => 0.0,
                'commissions' => 0.0,
                'followups' => 0,
            ];
            $cursor = match ($this->groupBy) {
                'weekly' => $cursor->addWeek(),
                'monthly' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $buckets;
    }

    private function timelineKey(CarbonImmutable $date): string
    {
        return match ($this->groupBy) {
            'weekly' => $date->startOfWeek()->format('o-W'),
            'monthly' => $date->format('Y-m'),
            default => $date->format('Y-m-d'),
        };
    }

    private function timelineLabel(CarbonImmutable $date): string
    {
        return match ($this->groupBy) {
            'weekly' => 'Semana '.$date->startOfWeek()->format('d/m').' - '.$date->endOfWeek()->format('d/m'),
            'monthly' => ucfirst($date->locale('es')->isoFormat('MMMM YYYY')),
            default => $date->format('d/m/Y'),
        };
    }
};
?>

<section class="crm-page crm-reports-page">
    <header class="crm-page-header">
        <div>
            <p class="crm-eyebrow">Gestion</p>
            <h1 class="crm-module-title">Reportes</h1>
            <p class="crm-module-description">Indicadores comerciales con aislamiento por rol y equipo.</p>
        </div>
        <div class="crm-actions">
            <flux:button icon="adjustments-horizontal" variant="primary" wire:click="toggleReportBuilder">
                {{ $showReportBuilder ? 'Volver al resumen' : 'Generar reporte' }}
            </flux:button>
        </div>
    </header>


    @if ($errors->hasAny(['from', 'to']))
        <div class="crm-empty" role="status">Revisa las fechas para consultar y exportar los reportes.</div>
    @else
        @if (! $reportReady && ! $showReportBuilder)
            @php
                $dashboard = $this->dashboardStats;
                $roleCopy = $this->reportRoleCopy();
                $maxSalesAmount = max(1, ...$this->salesEvolution->pluck('amount')->all());
                $maxPipeline = max(1, ...$this->pipeline->pluck('count')->all());
                $sourceTotal = max(1, $this->sources->sum('count'));
            @endphp

            <section class="crm-card crm-report-overview">
                <div class="crm-report-overview__header">
                    <div>
                        <p class="crm-eyebrow">{{ $roleCopy['scope'] }}</p>
                        <h2 class="crm-section-title">{{ $roleCopy['title'] }}</h2>
                        <p class="crm-module-description">{{ $roleCopy['description'] }}</p>
                    </div>
                    <div class="crm-report-period-badge">Periodo: {{ \Carbon\CarbonImmutable::parse($from)->format('d/m/Y') }} - {{ \Carbon\CarbonImmutable::parse($to)->format('d/m/Y') }}</div>
                </div>

                <div class="crm-report-metric-grid">
                    <article class="crm-report-metric-card crm-report-metric-card--blue"><span class="crm-report-metric-icon"><i class="bi bi-people"></i></span><p>Leads nuevos</p><strong>{{ number_format($dashboard['leads']) }}</strong><small class="{{ $dashboard['changes']['leads'] >= 0 ? 'is-positive' : 'is-negative' }}">{{ $dashboard['changes']['leads'] >= 0 ? '↗ +' : '↘ ' }}{{ number_format(abs($dashboard['changes']['leads']), 1) }}% <em>vs. periodo anterior</em></small></article>
                    <article class="crm-report-metric-card crm-report-metric-card--amber"><span class="crm-report-metric-icon"><i class="bi bi-calendar-check"></i></span><p>Reservas activas</p><strong>{{ number_format($dashboard['reservations']) }}</strong><small class="{{ $dashboard['changes']['reservations'] >= 0 ? 'is-positive' : 'is-negative' }}">{{ $dashboard['changes']['reservations'] >= 0 ? '↗ +' : '↘ ' }}{{ number_format(abs($dashboard['changes']['reservations']), 1) }}% <em>vs. periodo anterior</em></small></article>
                    <article class="crm-report-metric-card crm-report-metric-card--green"><span class="crm-report-metric-icon"><i class="bi bi-house-check"></i></span><p>Ventas cerradas</p><strong>{{ number_format($dashboard['sales']) }}</strong><small class="{{ $dashboard['changes']['sales'] >= 0 ? 'is-positive' : 'is-negative' }}">{{ $dashboard['changes']['sales'] >= 0 ? '↗ +' : '↘ ' }}{{ number_format(abs($dashboard['changes']['sales']), 1) }}% <em>vs. periodo anterior</em></small></article>
                    <article class="crm-report-metric-card crm-report-metric-card--violet"><span class="crm-report-metric-icon"><i class="bi bi-currency-dollar"></i></span><p>Monto vendido</p><strong>S/ {{ number_format($dashboard['sales_amount'], 0) }}</strong><small class="{{ $dashboard['changes']['sales_amount'] >= 0 ? 'is-positive' : 'is-negative' }}">{{ $dashboard['changes']['sales_amount'] >= 0 ? '↗ +' : '↘ ' }}{{ number_format(abs($dashboard['changes']['sales_amount']), 1) }}% <em>vs. periodo anterior</em></small></article>
                    <article class="crm-report-metric-card crm-report-metric-card--red"><span class="crm-report-metric-icon"><i class="bi bi-bar-chart-line"></i></span><p>Conversion</p><strong>{{ number_format($dashboard['conversion'], 1) }}%</strong><small class="{{ $dashboard['changes']['conversion'] >= 0 ? 'is-positive' : 'is-negative' }}">{{ $dashboard['changes']['conversion'] >= 0 ? '↗ +' : '↘ ' }}{{ number_format(abs($dashboard['changes']['conversion']), 1) }}% <em>vs. periodo anterior</em></small></article>
                    <article class="crm-report-metric-card crm-report-metric-card--cyan"><span class="crm-report-metric-icon"><i class="bi bi-percent"></i></span><p>Comisiones generadas</p><strong>S/ {{ number_format($dashboard['commissions'], 0) }}</strong><small class="{{ $dashboard['changes']['commissions'] >= 0 ? 'is-positive' : 'is-negative' }}">{{ $dashboard['changes']['commissions'] >= 0 ? '↗ +' : '↘ ' }}{{ number_format(abs($dashboard['changes']['commissions']), 1) }}% <em>vs. periodo anterior</em></small></article>
                </div>
            </section>


            <div class="crm-report-dashboard-grid">
                <section class="crm-card crm-report-chart-card">
                    <div class="crm-report-card-heading"><div><p class="crm-eyebrow">Evolucion de ventas</p><h2 class="crm-section-title">Monto vendido y cierres</h2><p class="crm-module-description">Comportamiento del periodo seleccionado.</p></div><flux:select wire:model.live="groupBy" label="Ver por"><option value="daily">Diario</option><option value="weekly">Semanal</option><option value="monthly">Mensual</option></flux:select></div>
                    <div class="crm-report-bars" aria-label="Evolucion de ventas">
                        @forelse ($this->salesEvolution as $point)
                            <div class="crm-report-bar-column" wire:key="sales-evolution-{{ $point['label'] }}"><div class="crm-report-bar-value">S/ {{ number_format($point['amount'], 0) }}</div><div class="crm-report-bar-track"><div class="crm-report-bar" style="height: {{ max(8, ($point['amount'] / $maxSalesAmount) * 100) }}%"></div></div><strong>{{ $point['sales'] }}</strong><span>{{ $point['label'] }}</span></div>
                        @empty
                            <div class="crm-empty">No hay ventas para el periodo seleccionado.</div>
                        @endforelse
                    </div>
                    <div class="crm-report-chart-legend"><span><i class="is-blue"></i>Monto vendido (S/)</span><span><i class="is-green"></i>Ventas cerradas</span></div>
                </section>

                <section class="crm-card crm-report-funnel-card">
                    <div class="crm-report-card-heading"><div><p class="crm-eyebrow">Embudo comercial</p><h2 class="crm-section-title">Leads por etapa</h2><p class="crm-module-description">Distribucion de leads dentro del proceso.</p></div><x-report-export-buttons :report="ReportType::Pipeline" /></div>
                    <div class="crm-report-funnel-list">
                        @forelse ($this->pipeline as $item)
                            <div class="crm-report-funnel-row" wire:key="dashboard-pipeline-{{ $item['name'] }}"><span>{{ $item['name'] }}</span><div class="crm-report-funnel-track"><div style="width: {{ min(100, ($item['count'] / $maxPipeline) * 100) }}%; background: {{ $item['color'] ?: '#008cff' }}"></div></div><strong>{{ $item['count'] }}</strong><small>{{ $dashboard['leads'] > 0 ? number_format(($item['count'] / $dashboard['leads']) * 100, 0) : 0 }}%</small></div>
                        @empty
                            <div class="crm-empty">Sin etapas configuradas.</div>
                        @endforelse
                    </div>
                </section>
            </div>

            <div class="crm-report-lower-grid">
                <section class="crm-card crm-report-channel-card"><div class="crm-report-card-heading"><div><p class="crm-eyebrow">Origen de leads</p><h2 class="crm-section-title">Leads por canal</h2></div><x-report-export-buttons :report="ReportType::Sources" /></div><div class="crm-report-channel-total"><strong>{{ number_format($dashboard['leads']) }}</strong><span>Leads totales</span></div><div class="crm-report-channel-list">@forelse ($this->sources as $index => $source)<div class="crm-report-channel-row" wire:key="dashboard-source-{{ $source['name'] }}"><span><i class="crm-report-channel-dot crm-report-channel-dot--{{ $index % 5 }}"></i>{{ $source['name'] }}</span><strong>{{ $source['count'] }}</strong><small>{{ number_format(($source['count'] / $sourceTotal) * 100, 0) }}%</small></div>@empty<div class="crm-empty">Sin datos por origen.</div>@endforelse</div></section>

                <section class="crm-card crm-report-performance-card"><div class="crm-report-card-heading"><div><p class="crm-eyebrow">Rendimiento {{ Auth::user()->isAsesor() ? 'personal' : 'por asesor' }}</p><h2 class="crm-section-title">{{ Auth::user()->isAsesor() ? 'Mi rendimiento' : 'Rendimiento del equipo' }}</h2><p class="crm-module-description">Desempeño comercial del periodo.</p></div><span class="crm-report-scope-badge">Periodo seleccionado</span></div><div class="crm-report-advisor-search"><flux:input wire:model.live.debounce.300ms="advisorSearch" icon="magnifying-glass" placeholder="Buscar asesor..." /></div><div class="crm-table-wrap"><table class="crm-table crm-report-performance-table"><thead><tr><th>#</th><th>Asesor</th><th>Ventas</th><th>Monto vendido</th><th>Conversion</th></tr></thead><tbody>@forelse ($this->advisorPerformance as $index => $advisor)<tr wire:key="dashboard-advisor-{{ $advisor['id'] }}"><td>{{ $index + 1 }}</td><td><span class="crm-report-advisor-cell"><i>{{ $advisor['initials'] }}</i>{{ $advisor['name'] }}</span></td><td>{{ $advisor['sales'] }}</td><td>S/ {{ number_format($advisor['sales_amount'], 0) }}</td><td><span class="crm-report-progress-value">{{ number_format($advisor['conversion'], 1) }}%</span><span class="crm-report-mini-progress"><i style="width: {{ min(100, $advisor['conversion']) }}%"></i></span></td></tr>@empty<tr><td colspan="5"><div class="crm-empty">Sin asesores para el periodo.</div></td></tr>@endforelse</tbody></table></div></section>
            </div>

            <section class="crm-card crm-report-detail-card"><div class="crm-report-card-heading"><div><p class="crm-eyebrow">Detalle de rendimiento</p><h2 class="crm-section-title">Actividad individual por asesor</h2><p class="crm-module-description">Leads, reservas, ventas y comisiones generadas.</p></div><span class="crm-report-scope-badge">{{ $roleCopy['scope'] }}</span></div><div class="crm-table-wrap"><table class="crm-table crm-report-detail-performance-table"><thead><tr><th>Asesor</th><th>Leads</th><th>Reservas</th><th>Ventas</th><th>Conversion</th><th>Comision</th><th>Estado</th></tr></thead><tbody>@forelse ($this->advisorPerformance as $advisor)<tr wire:key="dashboard-advisor-detail-{{ $advisor['id'] }}"><td><span class="crm-report-advisor-cell"><i>{{ $advisor['initials'] }}</i>{{ $advisor['name'] }}</span></td><td>{{ $advisor['leads'] }}</td><td>{{ $advisor['reservations'] }}</td><td>{{ $advisor['sales'] }}</td><td>{{ number_format($advisor['conversion'], 1) }}%</td><td>S/ {{ number_format($advisor['commission'], 0) }}</td><td><span class="crm-badge crm-badge--success">Activo</span></td></tr>@empty<tr><td colspan="7"><div class="crm-empty">Sin actividad para el periodo.</div></td></tr>@endforelse</tbody></table></div></section>
        @elseif ($showReportBuilder)
            <div class="crm-report-modal" role="presentation">
                <button type="button" class="crm-report-modal__backdrop" wire:click="closeReportBuilder" aria-label="Cerrar constructor de reportes"></button>
                <section class="crm-card crm-report-workspace" role="dialog" aria-modal="true" aria-labelledby="report-builder-title">
                <div class="crm-report-workspace__header">
                    <div>
                        <p class="crm-eyebrow">Generar reporte</p>
                        <h2 class="crm-section-title">Constructor y resultado del reporte</h2>
                        <p class="crm-module-description">Configura el intervalo, genera el reporte y revisa sus resultados en esta misma interfaz.</p>
                    </div>
                    <flux:button icon="arrow-left" wire:click="showDashboard">Volver al resumen</flux:button>
                </div>

                <section class="crm-card crm-panel-pad crm-report-builder crm-report-builder--full">
                    <div class="crm-section-heading">
                        <div>
                            <p class="crm-eyebrow">Constructor de reportes</p>
                            <h2 id="report-builder-title" class="crm-section-title">Selecciona que quieres analizar</h2>
                            <p class="crm-module-description">Filtra el periodo, el tipo de reporte y la frecuencia del calendario.</p>
                        </div>
                        <flux:button icon="x-mark" wire:click="closeReportBuilder">Cerrar</flux:button>
                    </div>

                    <form wire:submit="generateReport" class="crm-form-stack">
                        <div class="crm-form-grid">
                            <flux:select wire:model.live="reportType" label="Tipo de reporte" required>
                                @foreach (ReportType::cases() as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </flux:select>
                            <flux:select wire:model.live="groupBy" label="Ver por" required>
                                <option value="daily">Diario</option>
                                <option value="weekly">Semanal</option>
                                <option value="monthly">Mensual</option>
                            </flux:select>
                            <flux:input wire:model.live="from" type="date" label="Fecha inicial" required />
                            <flux:input wire:model.live="to" type="date" label="Fecha final" required />
                        </div>

                        <p class="crm-report-range-help">Puedes elegir un intervalo personalizado desde un solo dia hasta varios meses, anos o todo el historico.</p>

                        <div class="crm-report-period-actions" aria-label="Intervalos rapidos">
                            <span class="crm-report-period-label">Intervalo rapido:</span>
                            <flux:button type="button" size="sm" wire:click="setPeriod('today')">Hoy</flux:button>
                            <flux:button type="button" size="sm" wire:click="setPeriod('week')">Esta semana</flux:button>
                            <flux:button type="button" size="sm" wire:click="setPeriod('month')">Este mes</flux:button>
                            <flux:button type="button" size="sm" wire:click="setPeriod('quarter')">Ultimos 3 meses</flux:button>
                            <flux:button type="button" size="sm" wire:click="setPeriod('semester')">Ultimos 6 meses</flux:button>
                            <flux:button type="button" size="sm" wire:click="setPeriod('year')">Este ano</flux:button>
                            <flux:button type="button" size="sm" wire:click="setPeriod('all')">Todo el historico</flux:button>
                        </div>

                        <div class="crm-form-actions">
                            <flux:button type="button" wire:click="closeReportBuilder">Cancelar</flux:button>
                            <flux:button class="crm-report-builder__submit" type="submit" variant="primary" icon="play">Generar reporte</flux:button>
                        </div>
                    </form>
                </section>
            @if ($reportReady)
            <section class="crm-card crm-panel-pad crm-report-result">
                <div class="crm-section-heading">
                    <div>
                        <p class="crm-eyebrow">Reporte generado</p>
                        <h2 class="crm-section-title">{{ $this->selectedReport->label() }}</h2>
                        <p class="crm-module-description">{{ $from }} al {{ $to }} · Vista {{ $groupBy === 'daily' ? 'diaria' : ($groupBy === 'weekly' ? 'semanal' : 'mensual') }}</p>
                    </div>
                    <div class="crm-actions">
                        <flux:button icon="arrow-left" wire:click="showDashboard">Ver resumen</flux:button>
                        <x-report-export-buttons :report="$this->selectedReport" />
                    </div>
                </div>

                <div class="crm-report-timeline-wrap">
                    <div class="crm-report-timeline-heading">
                        <div>
                            <p class="crm-eyebrow">Calendario de actividad</p>
                            <h3 class="crm-propiedades-form-section-title">Evolucion del periodo</h3>
                        </div>
                        <span class="crm-report-timeline-caption">Ventas, propiedades, comisiones y actividad comercial</span>
                    </div>
                    <div class="crm-report-timeline">
                        @forelse ($this->timeline as $period)
                            @php($periodTotal = $period['leads'] + $period['properties'] + $period['sales'] + $period['sold_properties'] + $period['followups'])
                            <article class="crm-report-timeline-row" wire:key="report-period-{{ $period['label'] }}">
                                <div class="crm-report-timeline-period">
                                    <strong>{{ $period['label'] }}</strong>
                                    <span>{{ $periodTotal }} actividades</span>
                                </div>
                                <div class="crm-report-timeline-metrics">
                                    <span><b>{{ $period['leads'] }}</b> Leads</span>
                                    <span><b>{{ $period['properties'] }}</b> Propiedades</span>
                                    <span><b>{{ $period['sales'] }}</b> Ventas</span>
                                    <span><b>{{ $period['sold_properties'] }}</b> Vendidas</span>
                                    <span><b>S/ {{ number_format($period['commissions'], 2) }}</b> Comisiones</span>
                                    <span><b>{{ $period['followups'] }}</b> Seguimientos</span>
                                </div>
                            </article>
                        @empty
                            <div class="crm-empty">No hay actividad para el periodo seleccionado.</div>
                        @endforelse
                    </div>
                </div>

                <div class="crm-report-detail-table">
                    <div class="crm-report-timeline-heading">
                        <div>
                            <p class="crm-eyebrow">Detalle</p>
                            <h3 class="crm-propiedades-form-section-title">Registros del reporte</h3>
                        </div>
                        <span class="crm-report-timeline-caption">{{ count($this->reportRows) }} registros encontrados</span>
                    </div>
                    <div class="crm-table-wrap">
                        <table class="crm-table">
                            <thead>
                                <tr>
                                    @foreach ($this->selectedReport->headings() as $heading)
                                        <th>{{ $heading }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($this->reportRows as $row)
                                    <tr wire:key="report-row-{{ md5(serialize($row)) }}">
                                        @foreach ($row as $value)
                                            <td>{{ is_float($value) ? number_format($value, 2) : $value }}</td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ count($this->selectedReport->headings()) }}"><div class="crm-empty">No hay registros para el periodo seleccionado.</div></td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        @endif
        </section>
                </section>
            </div>
    @endif
    @endif
</section>
