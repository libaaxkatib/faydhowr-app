<?php

namespace App\Listeners\Audit;

use App\Events\Audit\AuditEvent;
use App\Models\AuditLog;

class StoreAuditLogListener
{
    public function handle(AuditEvent $event): void
    {
        AuditLog::query()->create([
            'admin_id' => $event->adminId,
            'action' => $event->action,
            'entity_type' => $event->entityType,
            'entity_id' => $event->entityId,
            'description' => $event->description,
            'metadata' => $event->metadata,
            'ip_address' => $event->ipAddress,
            'user_agent' => $event->userAgent,
            'created_at' => now(),
        ]);
    }
}
