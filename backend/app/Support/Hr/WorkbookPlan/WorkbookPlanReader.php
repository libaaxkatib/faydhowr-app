<?php

namespace App\Support\Hr\WorkbookPlan;

use InvalidArgumentException;

/**
 * Reads the two approved plan files (CSV, UTF-8, header row required):
 *
 *  companies: name, category, aliases
 *    aliases = original spellings separated by "|", each optionally followed by
 *    " @ rows 170,171" as its source reference.
 *  employees: source_row, employee_id, employee_number, name_key,
 *    expected_status, company, salary, start_date
 */
final class WorkbookPlanReader
{
    private const COMPANY_COLUMNS = ['name', 'category', 'aliases'];

    private const EMPLOYEE_COLUMNS = ['source_row', 'employee_id', 'employee_number', 'name_key', 'expected_status', 'company', 'salary', 'start_date'];

    /**
     * @return list<array{name: string, category: string, aliases: list<array{alias: string, reference: ?string}>}>
     */
    public function companies(string $path): array
    {
        $rows = [];

        foreach ($this->read($path, self::COMPANY_COLUMNS) as $line => $row) {
            if (! in_array($row['category'], ['company', 'guri'], true)) {
                throw new InvalidArgumentException("Companies file line {$line}: category must be 'company' or 'guri', got '{$row['category']}'.");
            }

            $aliases = [];

            foreach (array_filter(array_map('trim', explode('|', $row['aliases']))) as $part) {
                [$alias, $reference] = array_pad(array_map('trim', explode(' @ ', $part, 2)), 2, null);
                $aliases[] = ['alias' => $alias, 'reference' => $reference];
            }

            $rows[] = ['name' => trim($row['name']), 'category' => $row['category'], 'aliases' => $aliases];
        }

        $names = array_map(fn (array $r): string => mb_strtolower($r['name']), $rows);

        if (count($names) !== count(array_unique($names))) {
            throw new InvalidArgumentException('Companies file lists the same company name more than once (ignoring case).');
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function employees(string $path): array
    {
        $rows = [];

        foreach ($this->read($path, self::EMPLOYEE_COLUMNS) as $line => $row) {
            if (! ctype_digit($row['employee_id']) || ! ctype_digit($row['source_row'])) {
                throw new InvalidArgumentException("Employees file line {$line}: employee_id and source_row must be whole numbers.");
            }

            $rows[] = $row;
        }

        $ids = array_column($rows, 'employee_id');

        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Employees file lists the same employee_id more than once.');
        }

        return $rows;
    }

    /**
     * @param  list<string>  $columns
     * @return array<int, array<string, string>>
     */
    private function read(string $path, array $columns): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Plan file not found or unreadable: {$path}");
        }

        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, 0, ',', '"', '\\');
        $header = array_map(fn ($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $header ?: []);
        $missing = array_diff($columns, $header);

        if ($missing !== []) {
            fclose($handle);
            throw new InvalidArgumentException('Plan file is missing column(s): '.implode(', ', $missing));
        }

        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $line++;

            if ($cells === [null] || $cells === []) {
                continue;
            }

            $cells = array_pad($cells, count($header), '');
            $rows[$line] = array_map(fn ($v) => trim((string) $v), array_combine($header, array_slice($cells, 0, count($header))));
        }

        fclose($handle);

        return $rows;
    }
}
