<?php

namespace App\Data\Reports;

use Carbon\CarbonImmutable;

final class NormalizedReportFilters
{
    /**
     * @param  list<string>|string|null  $status
     * @param  list<string>|string|null  $paymentStatus
     */
    public function __construct(
        private readonly ?CarbonImmutable $dateFrom = null,
        private readonly ?CarbonImmutable $dateTo = null,
        private readonly array|string|null $status = null,
        private readonly ?int $customerId = null,
        private readonly ?int $supplierId = null,
        private readonly ?int $adminId = null,
        private readonly array|string|null $paymentStatus = null,
    ) {}

    public function dateFrom(): ?CarbonImmutable
    {
        return $this->dateFrom;
    }

    public function dateTo(): ?CarbonImmutable
    {
        return $this->dateTo;
    }

    /**
     * @return list<string>|string|null
     */
    public function status(): array|string|null
    {
        return $this->status;
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    public function supplierId(): ?int
    {
        return $this->supplierId;
    }

    public function adminId(): ?int
    {
        return $this->adminId;
    }

    /**
     * @return list<string>|string|null
     */
    public function paymentStatus(): array|string|null
    {
        return $this->paymentStatus;
    }

    /**
     * JSON-serializable representation containing only the applied (non-null) filters.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'date_from' => $this->dateFrom?->toIso8601String(),
            'date_to' => $this->dateTo?->toIso8601String(),
            'status' => $this->status,
            'customer_id' => $this->customerId,
            'supplier_id' => $this->supplierId,
            'admin_id' => $this->adminId,
            'payment_status' => $this->paymentStatus,
        ], fn (mixed $value): bool => $value !== null);
    }
}
