<?php

namespace App\DataTransferObjects\Home;

final readonly class CreateBeforeAfterItemData
{
    public function __construct(
        public ?int $serviceId,
        public string $title,
        public string $beforeImageUrl,
        public string $afterImageUrl,
        public int $sortOrder,
        public bool $isActive,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            serviceId: isset($validated['service_id']) ? (int) $validated['service_id'] : null,
            title: $validated['title'],
            beforeImageUrl: $validated['before_image_url'],
            afterImageUrl: $validated['after_image_url'],
            sortOrder: (int) ($validated['sort_order'] ?? 0),
            isActive: (bool) ($validated['is_active'] ?? true),
        );
    }
}
