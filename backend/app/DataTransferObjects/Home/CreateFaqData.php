<?php

namespace App\DataTransferObjects\Home;

final readonly class CreateFaqData
{
    public function __construct(
        public string $question,
        public string $answer,
        public int $sortOrder,
        public bool $isActive,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            question: $validated['question'],
            answer: $validated['answer'],
            sortOrder: (int) ($validated['sort_order'] ?? 0),
            isActive: (bool) ($validated['is_active'] ?? true),
        );
    }
}
