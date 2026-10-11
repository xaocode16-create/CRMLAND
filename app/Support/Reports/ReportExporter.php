<?php

namespace App\Support\Reports;

use App\Enums\ReportFormat;
use Dompdf\Dompdf;
use Dompdf\Options as PdfOptions;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ReportExporter
{
    public function download(ReportDocument $document, ReportFormat $format): StreamedResponse
    {
        return response()->streamDownload(function () use ($document, $format): void {
            match ($format) {
                ReportFormat::Xlsx => $this->writeXlsx($document),
                ReportFormat::Pdf => $this->writePdf($document),
                ReportFormat::Csv => $this->writeCsv($document),
            };
        }, $document->filename($format), [
            'Content-Type' => $format->contentType(),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function writeXlsx(ReportDocument $document): void
    {
        $path = tempnam(sys_get_temp_dir(), 'crmland-report-');

        if ($path === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del reporte.');
        }

        $writer = new Writer(new XlsxOptions(
            FALLBACK_STYLE: new Style(fontSize: 11, shouldWrapText: true),
            properties: new Properties(title: $document->type->label(), creator: 'CRMLAND', lastModifiedBy: $document->author),
        ));

        try {
            $writer->openToFile($path);
            $sheet = $writer->getCurrentSheet();
            $sheet->setName($document->type->label());
            $sheet->setColumnWidth(48, 1);
            $sheet->setColumnWidth(40, 2);
            $sheet->setColumnWidth(18, 3);
            $sheet->setSheetView(new SheetView(showGridLines: false, freezeRow: count($document->metadata()) + 6));

            $titleStyle = new Style(fontBold: true, fontSize: 18, fontColor: '0068CC');
            $headerStyle = new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: '0068CC');

            $writer->addRow($this->spreadsheetRow(['CRMLAND'], $titleStyle, 28));
            $writer->addRow($this->spreadsheetRow([$document->type->label()], new Style(fontBold: true, fontSize: 14), 24));
            $writer->addRow(Row::fromValues([]));

            foreach ($document->metadata() as $label => $value) {
                $writer->addRow($this->spreadsheetRow([$label, $value]));
            }

            $writer->addRow(Row::fromValues([]));
            $writer->addRow($this->spreadsheetRow($document->type->headings(), $headerStyle, 24));

            foreach ($document->rows as $index => $values) {
                $style = new Style(shouldWrapText: true, backgroundColor: $index % 2 === 0 ? 'F0F6FC' : 'FFFFFF');
                $writer->addRow($this->spreadsheetRow($values, $style));
            }

            if ($document->rows === []) {
                $writer->addRow($this->spreadsheetRow(['Sin datos para el periodo seleccionado.']));
            }

            if ($document->type->note() !== null) {
                $writer->addRow(Row::fromValues([]));
                $writer->addRow($this->spreadsheetRow([$document->type->note()]));
            }

            $writer->close();
            readfile($path);
        } finally {
            $writer->close();

            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param  list<string|int|float>  $values
     */
    private function spreadsheetRow(array $values, ?Style $style = null, float $height = 0): Row
    {
        $cells = array_map(static function (string|int|float $value) use ($style): Cell {
            if (is_string($value)) {
                return new StringCell($value, $style);
            }

            return Cell::fromValue($value, is_float($value) ? ($style ?? new Style)->withFormat('#,##0.00') : $style);
        }, $values);

        $height = max($height, 22, ...array_map(static fn (string|int|float $value): float => ceil(mb_strwidth((string) $value) / 40) * 16, $values));

        return new Row($cells, $height);
    }

    private function writePdf(ReportDocument $document): void
    {
        $pdf = new Dompdf(new PdfOptions([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'chroot' => resource_path('views/reports'),
            'fontCache' => storage_path('framework/cache'),
        ]));
        $pdf->setPaper('A4');
        $pdf->loadHtml(view('reports.pdf', ['document' => $document])->render(), 'UTF-8');
        $pdf->render();

        echo $pdf->output();
    }

    private function writeCsv(ReportDocument $document): void
    {
        $stream = fopen('php://output', 'wb');

        if ($stream === false) {
            throw new RuntimeException('No se pudo abrir el archivo del reporte.');
        }

        try {
            fwrite($stream, "\xEF\xBB\xBF");
            $rows = [['CRMLAND', $document->type->label()]];

            foreach ($document->metadata() as $label => $value) {
                $rows[] = [$label, $value];
            }

            $rows[] = [];
            $rows[] = $document->type->headings();
            array_push($rows, ...$document->rows);

            if ($document->type->note() !== null) {
                $rows[] = [$document->type->note()];
            }

            foreach ($rows as $row) {
                fputcsv($stream, array_map($this->safeCsvValue(...), $row), ',', '"', '');
            }
        } finally {
            fclose($stream);
        }
    }

    private function safeCsvValue(string|int|float $value): string|int|float
    {
        return is_string($value) && preg_match('/^[\x00-\x20]*[=+@-]/u', $value) === 1
            ? "'".$value
            : $value;
    }
}
