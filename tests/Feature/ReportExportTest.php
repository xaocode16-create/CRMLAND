<?php

use App\Enums\ReportType;
use App\Models\Commission;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use App\Models\Permission;
use App\Models\Property;
use App\Models\Sale;
use App\Models\User;
use App\Support\Reports\ReportDocument;
use Carbon\CarbonImmutable;
use Database\Seeders\CrmCoreSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

function reportDownloadContent(Testable $component): string
{
    $content = base64_decode($component->effects['download']['content'], true);

    expect($content)->not->toBeFalse();

    return $content;
}

/** @return list<list<string|null>> */
function reportCsvRows(string $content): array
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, substr($content, 3));
    rewind($stream);
    $rows = [];

    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }

    fclose($stream);

    return $rows;
}

it('exports only the permitted records and selected period for each role', function (string $role, int $count, string $amount, string $commission, array $channels, string $scope) {
    $this->seed(CrmCoreSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00'));
    $supervisor = User::factory()->supervisor()->create();
    $advisor = User::factory()->asesor()->create(['supervisor_user_id' => $supervisor->id]);
    $external = User::factory()->asesor()->create();
    $stage = LeadStatus::factory()->create(['name' => 'Etapa de prueba']);

    foreach ([[$supervisor, 'Canal supervisor', 100.25, 10.25], [$advisor, 'Canal asesor', 200.50, 20.50], [$external, 'Canal externo', 999.25, 99.25]] as [$owner, $channel, $price, $fee]) {
        $source = LeadSource::factory()->create(['name' => $channel]);
        $lead = Lead::factory()->create([
            'assigned_user_id' => $owner->id,
            'status_id' => $stage->id,
            'source_id' => $source->id,
            'entered_at' => '2026-10-05 12:00:00',
        ]);
        $property = Property::factory()->create(['assigned_user_id' => $owner->id, 'created_by' => $owner->id]);
        FollowUp::factory()->create(['assigned_user_id' => $owner->id, 'lead_id' => $lead->id, 'status' => 'PENDING']);
        $sale = Sale::factory()->create([
            'advisor_user_id' => $owner->id,
            'created_by' => $owner->id,
            'lead_id' => $lead->id,
            'property_id' => $property->id,
            'sold_at' => '2026-10-05 12:00:00',
            'price' => $price,
            'status' => 'REGISTERED',
        ]);
        Commission::factory()->create(['advisor_user_id' => $owner->id, 'sale_id' => $sale->id, 'amount' => $fee, 'status' => 'PENDING']);
        Lead::factory()->create(['assigned_user_id' => $owner->id, 'status_id' => $stage->id, 'source_id' => $source->id, 'entered_at' => '2026-09-30 23:59:59']);
        Sale::factory()->create(['advisor_user_id' => $owner->id, 'created_by' => $owner->id, 'lead_id' => $lead->id, 'property_id' => $property->id, 'sold_at' => '2026-10-06', 'price' => 50000, 'status' => 'CANCELLED']);
    }

    $actor = match ($role) {
        'administrador' => User::factory()->administrador()->create(),
        'supervisor' => $supervisor,
        'asesor' => $advisor,
    };
    $component = Livewire::actingAs($actor)->test('pages::reports.index');

    foreach (['resumen', 'etapas', 'canales'] as $report) {
        $component->call('exportReport', $report, 'csv')
            ->assertFileDownloaded("crmland-{$report}-2026-10-01-2026-10-09.csv", contentType: 'text/csv; charset=UTF-8');
        $rows = reportCsvRows(reportDownloadContent($component));

        expect($rows)->toContain(['Alcance', $scope]);

        if ($report === 'resumen') {
            expect($rows)
                ->toContain(['Leads nuevos', (string) $count, 'Registros'])
                ->toContain(['Propiedades visibles', (string) $count, 'Registros'])
                ->toContain(['Seguimientos pendientes', (string) $count, 'Registros'])
                ->toContain(['Ventas', (string) $count, 'Registros'])
                ->toContain(['Monto vendido', $amount, 'PEN'])
                ->toContain(['Comisiones pendientes', $commission, 'PEN']);
        } elseif ($report === 'etapas') {
            expect($rows)->toContain(['Etapa de prueba', (string) $count]);
        } else {
            $exportedChannels = array_column(array_slice($rows, 7), 0);

            expect($exportedChannels)->toEqualCanonicalizing($channels);
        }
    }
})->with([
    'administrador' => ['administrador', 3, '1300', '130', ['Canal supervisor', 'Canal asesor', 'Canal externo'], 'Todos los registros'],
    'supervisor' => ['supervisor', 2, '300.75', '30.75', ['Canal supervisor', 'Canal asesor'], 'Equipo a cargo'],
    'asesor' => ['asesor', 1, '200.5', '20.5', ['Canal asesor'], 'Mis registros'],
]);

it('downloads a readable file for every report and format', function (string $report, string $format, string $contentType) {
    $this->seed(CrmCoreSeeder::class);
    $advisor = User::factory()->asesor()->create();
    $component = Livewire::actingAs($advisor)->test('pages::reports.index')
        ->call('exportReport', $report, $format)
        ->assertFileDownloaded(contentType: $contentType);
    $content = reportDownloadContent($component);

    if ($format === 'pdf') {
        expect($content)->toStartWith('%PDF-')->toContain('%%EOF');
    } elseif ($format === 'csv') {
        expect($content)->toStartWith("\xEF\xBB\xBF")->toContain('CRMLAND');
    } else {
        $path = tempnam(sys_get_temp_dir(), 'report-test-');
        file_put_contents($path, $content);
        $reader = new Reader;

        try {
            $reader->open($path);
            $rows = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
            }

            expect($rows[0][0])->toBe('CRMLAND');

            if ($report === 'resumen') {
                expect($rows)->toContain(['Leads nuevos', 0, 'Registros']);
            } elseif ($report === 'canales') {
                expect(array_column($rows, 0))->toContain('Sin datos para el periodo seleccionado.');
            } else {
                expect(array_map(fn (array $row): array => array_slice($row, 0, 2), $rows))->toContain(['Etapa', 'Leads']);
            }
        } finally {
            $reader->close();
            unset($reader, $sheet, $row);
            gc_collect_cycles();
            unlink($path);
        }
    }
})->with(['resumen', 'etapas', 'canales'])->with([
    'Excel' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'PDF' => ['pdf', 'application/pdf'],
    'CSV' => ['csv', 'text/csv; charset=UTF-8'],
]);

it('rejects an export when the dates are invalid', function (string $from, string $to, string $field, string $rule, string $message) {
    $this->seed(CrmCoreSeeder::class);

    Livewire::actingAs(User::factory()->asesor()->create())->test('pages::reports.index')
        ->set(['from' => $from, 'to' => $to])
        ->call('exportReport', 'resumen', 'xlsx')
        ->assertHasErrors([$field => $rule])
        ->assertSee($message)
        ->assertNoFileDownloaded();
})->with([
    'initial date required' => ['', '2026-10-09', 'from', 'required', 'Selecciona la fecha inicial.'],
    'final date required' => ['2026-10-01', '', 'to', 'required', 'Selecciona la fecha final.'],
    'invalid initial date' => ['not-a-date', '2026-10-09', 'from', 'date_format', 'La fecha inicial no es valida.'],
    'invalid final date' => ['2026-10-01', '2026-02-30', 'to', 'date_format', 'La fecha final no es valida.'],
    'inverted period' => ['2026-10-09', '2026-10-01', 'to', 'after_or_equal', 'La fecha final debe ser igual o posterior a la inicial.'],
]);

it('rejects an unknown report or format', function (string $report, string $format, string $field) {
    $this->seed(CrmCoreSeeder::class);

    Livewire::actingAs(User::factory()->asesor()->create())->test('pages::reports.index')
        ->call('exportReport', $report, $format)
        ->assertHasErrors([$field])
        ->assertNoFileDownloaded();
})->with([
    ['../users', 'xlsx', 'report'],
    ['resumen', 'php', 'format'],
]);

it('requires authentication to access reports', function () {
    $this->get(route('reports.index'))->assertRedirect(route('login'));
});

it('denies reports without the reports permission', function () {
    $advisor = User::factory()->asesor()->create();

    $this->actingAs($advisor)->get(route('reports.index'))->assertForbidden();
});

it('rechecks permission when downloading after access is revoked', function () {
    $this->seed(CrmCoreSeeder::class);
    $advisor = User::factory()->asesor()->create();
    $component = Livewire::actingAs($advisor)->test('pages::reports.index');
    $advisor->role->permissions()->detach(Permission::query()->where('slug', 'reports.view')->firstOrFail());
    $advisor->unsetRelation('role');

    $component->call('exportReport', 'resumen', 'csv')->assertForbidden()->assertNoFileDownloaded();
});

it('includes both day boundaries and excludes records outside the period', function () {
    $this->seed(CrmCoreSeeder::class);
    $advisor = User::factory()->asesor()->create();
    $stage = LeadStatus::factory()->create(['name' => 'Etapa limites']);
    $source = LeadSource::factory()->create(['name' => 'Canal limites']);

    foreach (['2026-09-30 23:59:59', '2026-10-01 00:00:00', '2026-10-09 23:59:59', '2026-10-10 00:00:00'] as $enteredAt) {
        Lead::factory()->create(['assigned_user_id' => $advisor->id, 'status_id' => $stage->id, 'source_id' => $source->id, 'entered_at' => $enteredAt]);
    }

    $component = Livewire::actingAs($advisor)->test('pages::reports.index')
        ->set(['from' => '2026-10-01', 'to' => '2026-10-09'])
        ->assertSet('stats.leads', 2)
        ->call('exportReport', 'canales', 'csv');

    expect(reportCsvRows(reportDownloadContent($component)))->toContain(['Canal limites', '2']);
});

it('generates operational reports and shows the selected calendar view', function () {
    $this->seed(CrmCoreSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00'));
    $advisor = User::factory()->asesor()->create();
    $property = Property::factory()->create([
        'title' => 'Casa vendida de prueba',
        'assigned_user_id' => $advisor->id,
        'created_by' => $advisor->id,
        'status' => 'SOLD',
    ]);
    $lead = Lead::factory()->create(['assigned_user_id' => $advisor->id, 'entered_at' => '2026-10-05 10:00:00']);
    $sale = Sale::factory()->create([
        'lead_id' => $lead->id,
        'property_id' => $property->id,
        'advisor_user_id' => $advisor->id,
        'created_by' => $advisor->id,
        'sold_at' => '2026-10-05 12:00:00',
        'price' => 350000,
        'status' => 'APPROVED',
    ]);
    Commission::factory()->create([
        'sale_id' => $sale->id,
        'advisor_user_id' => $advisor->id,
        'amount' => 10500,
    ]);

    $component = Livewire::actingAs($advisor)->test('pages::reports.index')
        ->set([
            'from' => '2026-10-01',
            'to' => '2026-10-09',
            'reportType' => 'propiedades-vendidas',
            'groupBy' => 'weekly',
        ])
        ->call('generateReport')
        ->assertSet('reportReady', true)
        ->assertSee('Constructor de reportes')
        ->assertSee('Propiedades vendidas')
        ->assertSee('Semana');

    foreach ([
        'ventas' => 'Casa vendida de prueba',
        'comisiones' => 'Casa vendida de prueba',
        'propiedades' => 'Casa vendida de prueba',
        'propiedades-vendidas' => 'Casa vendida de prueba',
    ] as $report => $expectedValue) {
        $component->call('exportReport', $report, 'csv');
        $rows = reportCsvRows(reportDownloadContent($component));

        expect(collect($rows)->contains(fn (array $row): bool => in_array($expectedValue, $row, true)))->toBeTrue();
    }
});

it('renders the report dashboard within each role scope', function (string $role, string $heading, string $scope, string $visibleAdvisor, string $hiddenAdvisor) {
    $this->seed(CrmCoreSeeder::class);
    $supervisor = User::factory()->supervisor()->create(['name' => 'Supervisora General']);
    $advisor = User::factory()->asesor()->create(['name' => 'Asesora del equipo', 'supervisor_user_id' => $supervisor->id]);
    $external = User::factory()->asesor()->create(['name' => 'Asesor externo']);
    $actor = match ($role) {
        'administrador' => User::factory()->administrador()->create(),
        'supervisor' => $supervisor,
        default => $advisor,
    };

    Livewire::actingAs($actor)->test('pages::reports.index')
        ->assertSee($heading)
        ->assertSee($scope)
        ->assertSee($visibleAdvisor)
        ->assertDontSee($hiddenAdvisor);
})->with([
    'administrador' => ['administrador', 'Resumen general', 'Vista global del negocio', 'Asesora del equipo', 'Supervisora General'],
    'supervisor' => ['supervisor', 'Resumen de mi equipo', 'Equipo a cargo', 'Asesora del equipo', 'Asesor externo'],
    'asesor' => ['asesor', 'Mi desempeno', 'Mis registros', 'Asesora del equipo', 'Asesor externo'],
]);

it('applies report intervals from one day through a full year', function (string $period, string $from, string $to) {
    $this->seed(CrmCoreSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00'));

    Livewire::actingAs(User::factory()->asesor()->create())
        ->test('pages::reports.index')
        ->call('setPeriod', $period)
        ->assertSet('from', $from)
        ->assertSet('to', $to);
})->with([
    'one day' => ['today', '2026-10-09', '2026-10-09'],
    'three months' => ['quarter', '2026-08-01', '2026-10-09'],
    'six months' => ['semester', '2026-05-01', '2026-10-09'],
    'one year' => ['year', '2026-01-01', '2026-10-09'],
]);

it('shows the report constructor only after opening it', function () {
    $this->seed(CrmCoreSeeder::class);
    $component = Livewire::actingAs(User::factory()->asesor()->create())
        ->test('pages::reports.index')
        ->assertSet('showReportBuilder', false)
        ->assertDontSee('Constructor de reportes');

    $component->call('toggleReportBuilder')
        ->assertSet('showReportBuilder', true)
        ->assertSee('Constructor de reportes')
        ->assertDontSee('Resumen general')
        ->call('closeReportBuilder')
        ->assertSet('showReportBuilder', false);
});

it('neutralizes spreadsheet formulas in CSV text', function (string $name) {
    $this->seed(CrmCoreSeeder::class);
    $advisor = User::factory()->asesor()->create();
    $source = LeadSource::factory()->create(['name' => $name]);
    Lead::factory()->create(['assigned_user_id' => $advisor->id, 'source_id' => $source->id]);
    $component = Livewire::actingAs($advisor)->test('pages::reports.index')->call('exportReport', 'canales', 'csv');

    expect(reportCsvRows(reportDownloadContent($component)))->toContain(["'".$name, '1']);
})->with(['=SUM(1,1)', '+1+2', '-1+2', '@SUM(1,1)', "\t=HYPERLINK(\"https://example.com\")"]);

it('keeps formula-like Excel text as a string instead of an executable formula', function () {
    $this->seed(CrmCoreSeeder::class);
    $advisor = User::factory()->asesor()->create();
    $source = LeadSource::factory()->create(['name' => '=SUM(1,1)']);
    Lead::factory()->create(['assigned_user_id' => $advisor->id, 'source_id' => $source->id]);
    $component = Livewire::actingAs($advisor)->test('pages::reports.index')->call('exportReport', 'canales', 'xlsx');
    $path = tempnam(sys_get_temp_dir(), 'report-test-');
    file_put_contents($path, reportDownloadContent($component));
    $zip = new ZipArchive;

    try {
        expect($zip->open($path))->toBeTrue();
        $xml = new DOMDocument;
        $xml->loadXML($zip->getFromName('xl/worksheets/sheet1.xml'));
        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $cells = $xpath->query('//s:c[s:is/s:t[text()="=SUM(1,1)"]]');

        expect($cells->length)->toBe(1);
        expect($cells->item(0)->getAttribute('t'))->toBe('inlineStr');
        expect($xpath->query('s:f', $cells->item(0))->length)->toBe(0);
    } finally {
        $zip->close();
        unlink($path);
    }
});

it('escapes report labels and author information in the PDF template', function () {
    $document = new ReportDocument(
        type: ReportType::Sources,
        rows: [['<img src="https://example.com/private">', 1]],
        from: '2026-10-01',
        to: '2026-10-09',
        scope: 'Mis registros',
        author: '<script>alert(1)</script>',
        generatedAt: CarbonImmutable::parse('2026-10-09 12:00:00'),
    );
    $html = view('reports.pdf', compact('document'))->render();

    expect($html)->toContain('&lt;img', '&lt;script&gt;')->not->toContain('<img src=', '<script>');
});
