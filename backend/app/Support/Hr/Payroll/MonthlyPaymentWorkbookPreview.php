<?php

namespace App\Support\Hr\Payroll;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The full content of the monthly payment workbook as data, so the screen can show every worksheet, column, row, value, total and highlight BEFORE the
 * file is downloaded. It never rebuilds the sheets with its own rules: the workbook is written by MonthlyPaymentReportExporter (the one writer of the
 * download) and then READ BACK cell by cell, so the preview is the file's own content, not a second description of it.
 *
 * `content_hash` is the SHA-256 of that content. The download recomputes it from the file it is about to send and refuses to send a file whose content
 * differs from the preview the person looked at (the data changed in between), so "what was previewed" and "what was downloaded" cannot differ.
 *
 * Cell = null (empty) or [text as Excel shows it, style index]. Style index 0 is the plain style; the others are listed in `styles`.
 */
final class MonthlyPaymentWorkbookPreview
{
    public function __construct(private readonly MonthlyPaymentReportExporter $exporter) {}

    /**
     * @param  array<string, mixed>  $report  from MonthlyPaymentReportBuilder::build
     * @return array{sheets: list<array<string, mixed>>, content_hash: string}
     */
    public function fromReport(array $report): array
    {
        $path = tempnam(sys_get_temp_dir(), 'payment-preview').'.xlsx';

        try {
            $this->exporter->write($report, $path);

            return $this->fromFile($path);
        } finally {
            @unlink($path);
        }
    }

    /** @return array{sheets: list<array<string, mixed>>, content_hash: string} */
    public function fromFile(string $path): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $spreadsheet = $reader->load($path);
        $sheets = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            $sheets[] = $this->sheet($worksheet);
        }

        $spreadsheet->disconnectWorksheets();

        return ['sheets' => $sheets, 'content_hash' => hash('sha256', json_encode($sheets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION))];
    }

    /** @return array<string, mixed> */
    private function sheet(Worksheet $ws): array
    {
        $lastRow = $ws->getHighestDataRow();
        $lastColumn = $ws->getHighestDataColumn();
        $columnCount = Coordinate::columnIndexFromString($lastColumn);
        $filter = $ws->getAutoFilter()->getRange();
        $headerRow = $filter === '' ? null : (int) preg_replace('/^[A-Z]+(\d+):.*$/', '$1', $filter);

        $styles = [[]];
        $styleIndex = ['[]' => 0];
        $rows = [];
        $headers = [];

        for ($r = 1; $r <= $lastRow; $r++) {
            $row = [];

            for ($c = 1; $c <= $columnCount; $c++) {
                $coordinate = Coordinate::stringFromColumnIndex($c).$r;

                if (! $ws->cellExists($coordinate)) {
                    $row[] = null;

                    continue;
                }

                $cell = $ws->getCell($coordinate);
                $text = (string) $cell->getFormattedValue();
                $style = $this->styleOf($ws, $coordinate, $cell->getDataType() === DataType::TYPE_NUMERIC);
                $key = json_encode($style);

                if (! isset($styleIndex[$key])) {
                    $styleIndex[$key] = count($styles);
                    $styles[] = $style;
                }

                $row[] = $text === '' && $styleIndex[$key] === 0 ? null : [$text, $styleIndex[$key]];

                if ($headerRow === $r) {
                    $headers[] = $text;
                }
            }

            while ($row !== [] && end($row) === null) {
                array_pop($row);
            }

            $rows[] = $row;
        }

        $widths = [];
        for ($c = 1; $c <= $columnCount; $c++) {
            $width = $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c))->getWidth();
            $widths[] = $width > 0 ? round($width, 1) : null;
        }

        return [
            'name' => $ws->getTitle(),
            'row_count' => $lastRow,
            'column_count' => $columnCount,
            'header_row' => $headerRow,
            'headers' => $headers,
            'frozen_at' => $ws->getFreezePane(),
            'filter_range' => $filter === '' ? null : $filter,
            'column_widths' => $widths,
            'styles' => $styles,
            'rows' => $rows,
        ];
    }

    /**
     * The look that carries meaning: bold, the fill (a highlighted missing number, a Temporary row, a header), a coloured font, numbers (right aligned).
     *
     * @return array<string, mixed>
     */
    private function styleOf(Worksheet $ws, string $coordinate, bool $numeric): array
    {
        $style = $ws->getStyle($coordinate);
        $out = [];

        if ($style->getFont()->getBold()) {
            $out['bold'] = true;
        }

        $font = $style->getFont()->getColor()->getRGB();
        if ($font !== '000000') {
            $out['color'] = $font;
        }

        if ($style->getFill()->getFillType() === Fill::FILL_SOLID) {
            $out['fill'] = $style->getFill()->getStartColor()->getRGB();
        }

        if ($numeric) {
            $out['number'] = true;
        }

        if ((int) $style->getAlignment()->getIndent() > 0) {
            $out['indent'] = (int) $style->getAlignment()->getIndent();
        }

        return $out;
    }
}
