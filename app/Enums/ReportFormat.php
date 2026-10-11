<?php

namespace App\Enums;

enum ReportFormat: string
{
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';
    case Csv = 'csv';

    public function label(): string
    {
        return match ($this) {
            self::Xlsx => 'Excel',
            self::Pdf => 'PDF',
            self::Csv => 'CSV',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Xlsx => 'table-cells',
            self::Pdf => 'document-text',
            self::Csv => 'document-arrow-down',
        };
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
            self::Csv => 'text/csv; charset=UTF-8',
        };
    }
}
