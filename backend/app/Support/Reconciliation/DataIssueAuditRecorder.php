<?php

namespace App\Support\Reconciliation;

use App\Models\Admin;
use App\Models\DataIssue;
use App\Models\DataIssueAuditLog;

/**
 * Writes one DataIssueAuditLog row per changed field — see the migration's
 * own docblock for why this is a dedicated table rather than the generic
 * AuditLog. Never called for a field that didn't actually change.
 */
final class DataIssueAuditRecorder
{
    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [old, new]
     */
    public function record(DataIssue $issue, ?Admin $actor, array $changes): void
    {
        $now = now();

        foreach ($changes as $field => [$old, $new]) {
            if ($this->normalize($old) === $this->normalize($new)) {
                continue;
            }

            DataIssueAuditLog::query()->create([
                'data_issue_id' => $issue->id,
                'admin_id' => $actor?->id,
                'field_changed' => $field,
                'old_value' => $this->stringify($old),
                'new_value' => $this->stringify($new),
                'created_at' => $now,
            ]);
        }
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return $value;
    }

    private function stringify(mixed $value): ?string
    {
        $normalized = $this->normalize($value);

        if ($normalized === null) {
            return null;
        }

        if (is_bool($normalized)) {
            return $normalized ? 'true' : 'false';
        }

        return (string) $normalized;
    }
}
