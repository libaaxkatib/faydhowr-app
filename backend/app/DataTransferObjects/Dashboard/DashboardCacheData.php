<?php

namespace App\DataTransferObjects\Dashboard;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * Immutable cache section of the dashboard response metadata.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class DashboardCacheData implements Arrayable, JsonSerializable
{
    public function __construct(
        public bool $enabled,
        public int $ttlSeconds,
    ) {}

    /**
     * @return array{enabled: bool, ttl_seconds: int}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'ttl_seconds' => $this->ttlSeconds,
        ];
    }

    /**
     * @return array{enabled: bool, ttl_seconds: int}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
