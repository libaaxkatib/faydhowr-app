<?php

namespace App\Events\Notification;

use App\Models\Admin;
use App\Models\CustomerProfile;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class NotificationRequested
{
    use Dispatchable, SerializesModels;

    public string $eventId;

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public Model $recipient,
        public string $templateKey,
        public array $variables = [],
        public ?array $data = null,
        ?string $eventId = null,
        public ?string $language = null,
    ) {
        $this->assertSupportedRecipient($recipient);

        if (trim($templateKey) === '') {
            throw new DomainException('TEMPLATE_KEY_REQUIRED');
        }

        $this->eventId = $eventId ?? (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $variables
     * @param  array<string, mixed>|null  $data
     */
    public static function make(
        Model $recipient,
        string $templateKey,
        array $variables = [],
        ?array $data = null,
        ?string $eventId = null,
        ?string $language = null,
    ): self {
        return new self(
            recipient: $recipient,
            templateKey: $templateKey,
            variables: $variables,
            data: $data,
            eventId: $eventId,
            language: $language,
        );
    }

    private function assertSupportedRecipient(Model $recipient): void
    {
        if ($recipient instanceof Admin || $recipient instanceof CustomerProfile) {
            return;
        }

        throw new DomainException('UNSUPPORTED_NOTIFICATION_RECIPIENT');
    }
}
