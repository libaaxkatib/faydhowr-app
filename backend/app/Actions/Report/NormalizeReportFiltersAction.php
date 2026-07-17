<?php

namespace App\Actions\Report;

use App\Data\Reports\NormalizedReportFilters;
use App\Exceptions\Reports\InvalidReportFilterException;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DateTimeInterface;

class NormalizeReportFiltersAction
{
    private const SUPPORTED_FILTERS = [
        'date_from',
        'date_to',
        'status',
        'customer_id',
        'supplier_id',
        'admin_id',
        'payment_status',
        'report_type',
    ];

    /**
     * Normalize raw request filters into an immutable value object.
     * This is the single place where report filters are normalized and validated.
     *
     * @param  array<string, mixed>  $filters
     */
    public function handle(array $filters): NormalizedReportFilters
    {
        foreach (array_keys($filters) as $key) {
            if (! is_string($key) || ! in_array($key, self::SUPPORTED_FILTERS, true)) {
                throw InvalidReportFilterException::unsupportedFilter((string) $key);
            }
        }

        $dateFrom = $this->normalizeDate('date_from', $filters['date_from'] ?? null);
        $dateTo = $this->normalizeDate('date_to', $filters['date_to'] ?? null);

        if ($dateFrom !== null && $dateTo !== null && $dateFrom->greaterThan($dateTo)) {
            throw InvalidReportFilterException::invalidDateRange();
        }

        return new NormalizedReportFilters(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            status: $this->normalizeStatus('status', $filters['status'] ?? null),
            customerId: $this->normalizeId('customer_id', $filters['customer_id'] ?? null),
            supplierId: $this->normalizeId('supplier_id', $filters['supplier_id'] ?? null),
            adminId: $this->normalizeId('admin_id', $filters['admin_id'] ?? null),
            paymentStatus: $this->normalizeStatus('payment_status', $filters['payment_status'] ?? null),
        );
    }

    private function normalizeDate(string $key, mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value)) {
            throw InvalidReportFilterException::invalidDate($key, $value);
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($trimmed);
        } catch (InvalidFormatException) {
            throw InvalidReportFilterException::invalidDate($key, $value);
        }
    }

    private function normalizeId(string $key, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        throw InvalidReportFilterException::invalidId($key, $value);
    }

    /**
     * @return list<string>|string|null
     */
    private function normalizeStatus(string $key, mixed $value): array|string|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));

            return $normalized === '' ? null : $normalized;
        }

        if (is_array($value)) {
            $cleaned = [];

            foreach ($value as $item) {
                if ($item === null || $item === '') {
                    continue;
                }

                if (! is_string($item)) {
                    throw InvalidReportFilterException::invalidStructure($key);
                }

                $normalized = mb_strtolower(trim($item));

                if ($normalized !== '') {
                    $cleaned[] = $normalized;
                }
            }

            return $cleaned === [] ? null : $cleaned;
        }

        throw InvalidReportFilterException::invalidStructure($key);
    }
}
