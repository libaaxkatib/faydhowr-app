<?php

namespace App\Support\Hr\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * Reads an uploaded .xlsx or .csv straight from PHP's temporary upload (the file is never moved or
 * stored) into rows of canonical-column => text. Every whole-file problem is reported as an
 * ImportFileException BEFORE anything is staged: wrong type or content, too large, too many rows or
 * columns, unreadable, header missing, required column missing or ambiguous.
 *
 * Only the first sheet is read. Excel sheets can carry stray cells out to column XFD, so reading is
 * limited to the first MAX_COLUMNS columns and the first MAX_ROWS data rows.
 */
final class WorkbookReader
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_ROWS = 2000;

    public const MAX_COLUMNS = 40;

    /** The header row must be within the first rows of the sheet. */
    private const HEADER_SEARCH_ROWS = 5;

    /**
     * @param  array<string, list<string>>  $columns  canonical => accepted spellings
     * @param  list<string>  $required  canonical names that must be present
     * @param  list<string>  $ignored  recognised headers that are deliberately not imported
     */
    public function read(UploadedFile $file, array $columns, array $required, array $ignored = []): ParsedWorkbook
    {
        $extension = $this->extension($file);
        $this->assertSafe($file, $extension);

        try {
            $grid = $extension === 'csv' ? $this->readCsv($file) : $this->readXlsx($file);
        } catch (ImportFileException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw new ImportFileException('FILE_UNREADABLE', 'The workbook could not be read. Save it again as .xlsx (or .csv) and retry.');
        }

        return $this->map($grid, $columns, $required, $ignored);
    }

    public function extension(UploadedFile $file): string
    {
        return strtolower((string) pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION));
    }

    private function assertSafe(UploadedFile $file, string $extension): void
    {
        if (! $file->isValid()) {
            throw new ImportFileException('FILE_UPLOAD_FAILED', 'The file upload did not complete. Please try again.');
        }

        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            throw new ImportFileException('FILE_TYPE_NOT_ALLOWED', 'Only .xlsx and .csv files are accepted.');
        }

        $size = (int) $file->getSize();

        if ($size <= 0) {
            throw new ImportFileException('FILE_EMPTY', 'The file is empty.');
        }

        if ($size > self::MAX_BYTES) {
            throw new ImportFileException('FILE_TOO_LARGE', 'The file is larger than '.(self::MAX_BYTES / 1024 / 1024).' MB.');
        }

        $path = (string) $file->getRealPath();
        $head = (string) file_get_contents($path, false, null, 0, 8);

        if ($extension === 'xlsx') {
            // An .xlsx is a ZIP archive; anything else renamed to .xlsx is refused.
            if (! str_starts_with($head, "PK\x03\x04")) {
                throw new ImportFileException('FILE_CONTENT_MISMATCH', 'This file is not a real .xlsx workbook.');
            }

            return;
        }

        // CSV: text only. Binary content (a renamed spreadsheet or executable) contains NUL bytes.
        $sample = (string) file_get_contents($path, false, null, 0, 8192);

        if (str_contains($sample, "\0") || str_starts_with($head, 'PK')) {
            throw new ImportFileException('FILE_CONTENT_MISMATCH', 'This file is not a plain-text .csv.');
        }
    }

    /**
     * @return list<array{int, list<string>}> [sheet row number, cell texts]
     */
    private function readCsv(UploadedFile $file): array
    {
        $handle = fopen((string) $file->getRealPath(), 'r');

        if ($handle === false) {
            throw new ImportFileException('FILE_UNREADABLE', 'The file could not be opened.');
        }

        $first = (string) fgets($handle);
        rewind($handle);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $rows = [];
        $line = 0;
        $nonEmpty = 0;

        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $line++;

            if ($line === 1 && isset($cells[0])) {
                $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
            }

            $cells = array_map(fn ($c): string => trim((string) $c), array_slice($cells, 0, self::MAX_COLUMNS));

            if (array_filter($cells, fn (string $c): bool => $c !== '') === []) {
                continue;
            }

            if (++$nonEmpty > self::MAX_ROWS + self::HEADER_SEARCH_ROWS) {
                fclose($handle);

                throw new ImportFileException('TOO_MANY_ROWS', 'The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.');
            }

            $rows[] = [$line, $cells];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array{int, list<string>}>
     */
    private function readXlsx(UploadedFile $file): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $limit = self::MAX_ROWS + self::HEADER_SEARCH_ROWS + 2;
        $reader->setReadFilter(new class($limit, self::MAX_COLUMNS) implements IReadFilter
        {
            public function __construct(private readonly int $maxRow, private readonly int $maxColumns) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->maxRow && Coordinate::columnIndexFromString($columnAddress) <= $this->maxColumns;
            }
        });

        $book = $reader->load((string) $file->getRealPath());
        $sheet = $book->getSheet(0);
        $rows = [];
        $nonEmpty = 0;
        $highestRow = min($sheet->getHighestDataRow(), $limit);

        for ($r = 1; $r <= $highestRow; $r++) {
            $cells = [];

            for ($c = 1; $c <= self::MAX_COLUMNS; $c++) {
                $cells[] = $this->cellText($sheet->getCell([$c, $r])->getValue());
            }

            while ($cells !== [] && end($cells) === '') {
                array_pop($cells);
            }

            if ($cells === []) {
                continue;
            }

            if (++$nonEmpty > self::MAX_ROWS + self::HEADER_SEARCH_ROWS) {
                $book->disconnectWorksheets();

                throw new ImportFileException('TOO_MANY_ROWS', 'The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.');
            }

            $rows[] = [$r, $cells];
        }

        $book->disconnectWorksheets();

        return $rows;
    }

    private function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        if (is_object($value)) {
            return trim((string) (method_exists($value, 'getPlainText') ? $value->getPlainText() : $value));
        }

        return trim((string) $value);
    }

    /**
     * @param  list<array{int, list<string>}>  $grid
     * @param  array<string, list<string>>  $columns
     * @param  list<string>  $required
     * @param  list<string>  $ignored
     */
    private function map(array $grid, array $columns, array $required, array $ignored): ParsedWorkbook
    {
        if ($grid === []) {
            throw new ImportFileException('FILE_EMPTY', 'The file has no data.');
        }

        $spellings = [];

        foreach ($columns as $canonical => $accepted) {
            foreach (array_unique([$canonical, ...$accepted]) as $spelling) {
                $spellings[self::headerKey($spelling)] = $canonical;
            }
        }

        $ignoredKeys = array_map(self::headerKey(...), $ignored);

        // The header is the first row, within the first few rows, that names at least one known column.
        $headerAt = null;

        foreach (array_slice($grid, 0, self::HEADER_SEARCH_ROWS) as $index => [$rowNumber, $cells]) {
            foreach ($cells as $cell) {
                if (isset($spellings[self::headerKey($cell)])) {
                    $headerAt = $index;

                    break 2;
                }
            }
        }

        if ($headerAt === null) {
            throw new ImportFileException('HEADER_NOT_FOUND', 'No header row was found. The first row must name the columns: '.implode(', ', $required).'.');
        }

        /** @var array<int, string> $positions column index => canonical */
        $positions = [];
        $unknown = [];
        $seen = [];

        foreach ($grid[$headerAt][1] as $index => $header) {
            $key = self::headerKey($header);

            if ($key === '') {
                continue;
            }

            if (isset($spellings[$key])) {
                $canonical = $spellings[$key];

                if (isset($seen[$canonical])) {
                    throw new ImportFileException('DUPLICATE_COLUMN', "The column '{$canonical}' appears more than once in the header row.");
                }

                $seen[$canonical] = true;
                $positions[$index] = $canonical;
            } else {
                $unknown[] = in_array($key, $ignoredKeys, true) ? "{$header} (ignored on purpose)" : $header;
            }
        }

        $missing = array_values(array_diff($required, array_values($positions)));

        if ($missing !== []) {
            throw new ImportFileException('MISSING_COLUMNS', 'The file is missing required column(s): '.implode(', ', $missing).'.');
        }

        $rows = [];

        foreach (array_slice($grid, $headerAt + 1) as [$rowNumber, $cells]) {
            $mapped = array_fill_keys(array_keys($columns), '');

            foreach ($positions as $index => $canonical) {
                $mapped[$canonical] = $cells[$index] ?? '';
            }

            if (array_filter($mapped, fn (string $v): bool => $v !== '') === []) {
                continue;
            }

            $rows[] = ['row_number' => $rowNumber, 'cells' => $mapped];
        }

        if ($rows === []) {
            throw new ImportFileException('FILE_EMPTY', 'The file has a header but no data rows.');
        }

        if (count($rows) > self::MAX_ROWS) {
            throw new ImportFileException('TOO_MANY_ROWS', 'The file has more than '.self::MAX_ROWS.' rows. Split it into smaller files.');
        }

        return new ParsedWorkbook($rows, $unknown);
    }

    public static function headerKey(string $header): string
    {
        return trim(preg_replace('/[\s_\-]+/u', ' ', mb_strtolower(preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header)) ?? '');
    }
}
