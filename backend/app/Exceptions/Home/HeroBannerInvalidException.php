<?php

namespace App\Exceptions\Home;

use RuntimeException;

class HeroBannerInvalidException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        public readonly array $errors,
    ) {
        parent::__construct($message);
    }

    public static function missingActionReference(): self
    {
        return new self('The given data was invalid.', [
            'action_reference' => ['The action reference is required for actionable banners.'],
        ]);
    }

    public static function forbiddenActionReference(): self
    {
        return new self('The given data was invalid.', [
            'action_reference' => ['The action reference must be null when the action type is none.'],
        ]);
    }

    public static function invalidSchedule(): self
    {
        return new self('The given data was invalid.', [
            'ends_at' => ['The schedule end must be after the schedule start.'],
        ]);
    }
}
