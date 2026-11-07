<?php

namespace App\Support\I18n;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\SystemSetting;

/**
 * The one place that writes a Somali / English wording override (a row of `system_settings`, category `i18n`, key = message key, value = {"en"?, "so"?}).
 * Used by the Super Admin editor (TranslationOverrideController) and by the approval of a proposal (TranslationProposalController), so both go through
 * the same limit, the same row lock and the same audit entry. Nothing here touches the built-in catalogue or the pinned approved wording.
 */
final class TranslationOverrides
{
    public const CATEGORY = 'i18n';

    /** Upper bound on stored overrides, so the table cannot grow without limit. The built-in catalogue has about 3,200 messages. */
    public const MAX_OVERRIDES = 5000;

    /**
     * Stores `$value` ({"en"?, "so"?}) as the override of `$key` and audits it. Call inside a transaction. Returns null when the limit of edited messages
     * has been reached (nothing written).
     *
     * @param  array{en?: string, so?: string}  $value
     * @param  array<string, mixed>  $extraMetadata
     */
    public static function save(Admin $actor, string $key, array $value, string $description, array $extraMetadata = []): ?SystemSetting
    {
        $row = SystemSetting::query()->category(self::CATEGORY)->where('key', $key)->lockForUpdate()->first();

        if ($row === null && SystemSetting::query()->category(self::CATEGORY)->count() >= self::MAX_OVERRIDES) {
            return null;
        }

        $before = $row === null ? null : self::clean($row->value);

        $row ??= new SystemSetting(['category' => self::CATEGORY, 'key' => $key, 'is_sensitive' => false]);
        $row->fill(['value' => $value, 'updated_by' => $actor->id])->save();

        event(AuditEvent::record(
            action: $before === null ? AuditAction::Create : AuditAction::Update,
            admin: $actor,
            description: $description,
            entityType: SystemSetting::class,
            entityId: $row->id,
            metadata: ['key' => $key, 'before' => $before, 'after' => $value, ...$extraMetadata],
        ));

        return $row;
    }

    /**
     * @return array{en?: string, so?: string}|null
     */
    public static function clean(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $clean = array_filter([
            'en' => is_string($value['en'] ?? null) && trim($value['en']) !== '' ? $value['en'] : null,
            'so' => is_string($value['so'] ?? null) && trim($value['so']) !== '' ? $value['so'] : null,
        ], fn (?string $text): bool => $text !== null);

        return $clean === [] ? null : $clean;
    }
}
