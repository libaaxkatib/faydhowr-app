<?php

namespace App\Events\Audit;

use App\Enums\AuditAction;
use App\Models\Admin;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\SerializesModels;

class AuditEvent
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public AuditAction $action,
        public ?int $adminId,
        public ?string $entityType,
        public ?int $entityId,
        public string $description,
        public ?array $metadata = null,
        public ?string $ipAddress = null,
        public ?string $userAgent = null,
    ) {}

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public static function record(
        AuditAction $action,
        ?Admin $admin,
        string $description,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $metadata = null,
        ?Request $request = null,
    ): self {
        $request ??= request();

        return new self(
            action: $action,
            adminId: $admin?->id,
            entityType: $entityType,
            entityId: $entityId,
            description: $description,
            metadata: $metadata,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );
    }
}
