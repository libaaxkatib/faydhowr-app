<?php

namespace App\DataTransferObjects\Dashboard;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Immutable KPI payload for a single dashboard widget.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class DashboardKpiData implements Arrayable, JsonSerializable
{
    public function __construct(
        public int|float $total,
        public string $label,
        public string $unit,
        public string $updatedAt,
    ) {}

    /**
     * @return array{total: int|float, label: string, unit: string, updated_at: string}
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'label' => $this->label,
            'unit' => $this->unit,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @return array{total: int|float, label: string, unit: string, updated_at: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
