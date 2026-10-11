<?php

namespace App\Support\Reports;

use App\Enums\ReportFormat;
use App\Enums\ReportType;
use Carbon\CarbonImmutable;

final readonly class ReportDocument
{
    /**
     * @param  list<list<string|int|float>>  $rows
     */
    public function __construct(
        public ReportType $type,
        public array $rows,
        public string $from,
        public string $to,
        public string $scope,
        public string $author,
        public CarbonImmutable $generatedAt,
    ) {}

    public function filename(ReportFormat $format): string
    {
        return "crmland-{$this->type->value}-{$this->from}-{$this->to}.{$format->value}";
    }

    /** @return array<string, string> */
    public function metadata(): array
    {
        return [
            'Periodo' => "{$this->from} al {$this->to}",
            'Alcance' => $this->scope,
            'Generado por' => $this->author,
            'Fecha (Lima)' => $this->generatedAt->format('d/m/Y H:i'),
        ];
    }
}
