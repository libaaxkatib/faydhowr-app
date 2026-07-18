<?php

namespace App\DataTransferObjects\Home;

use App\Enums\Home\HeroBannerActionType;
use Carbon\CarbonImmutable;

final readonly class CreateHeroBannerData
{
    public function __construct(
        public string $title,
        public ?string $subtitle,
        public string $imageUrl,
        public HeroBannerActionType $actionType,
        public ?string $actionReference,
        public int $sortOrder,
        public bool $isActive,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            title: $validated['title'],
            subtitle: $validated['subtitle'] ?? null,
            imageUrl: $validated['image_url'],
            actionType: HeroBannerActionType::from($validated['action_type']),
            actionReference: $validated['action_reference'] ?? null,
            sortOrder: (int) ($validated['sort_order'] ?? 0),
            isActive: (bool) ($validated['is_active'] ?? true),
            startsAt: isset($validated['starts_at']) ? CarbonImmutable::parse($validated['starts_at']) : null,
            endsAt: isset($validated['ends_at']) ? CarbonImmutable::parse($validated['ends_at']) : null,
        );
    }
}
