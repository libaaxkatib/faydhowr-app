<?php

namespace App\DataTransferObjects\Dashboard;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Immutable top-level dashboard response metadata.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class DashboardMetadataData implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $generatedAt,
        public DashboardCacheData $cache,
        public DashboardFilterData $filter,
        public int $version,
    ) {}

    /**
     * @return array{generated_at: string, cache: array{enabled: bool, ttl_seconds: int}, filter: array{type: string, start_date: ?string, end_date: ?string}, version: int}
     */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt,
            'cache' => $this->cache->toArray(),
            'filter' => $this->filter->toArray(),
            'version' => $this->version,
        ];
    }

    /**
     * @return array{generated_at: string, cache: array{enabled: bool, ttl_seconds: int}, filter: array{type: string, start_date: ?string, end_date: ?string}, version: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
