<?php

namespace App\DataTransferObjects\Dashboard;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Immutable filter section of the dashboard response metadata.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class DashboardFilterData implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $type,
        public ?string $startDate,
        public ?string $endDate,
    ) {}

    /**
     * @return array{type: string, start_date: ?string, end_date: ?string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
    }

    /**
     * @return array{type: string, start_date: ?string, end_date: ?string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
