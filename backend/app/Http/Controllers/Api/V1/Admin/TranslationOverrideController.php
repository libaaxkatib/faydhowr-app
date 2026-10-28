<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\SystemSetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Super Admin wording edits for the Web Panel's Somali / English text.
 *
 * The built-in wording lives in the Web Panel's catalogue. An edit is stored here as an override of one message key, one row of `system_settings`
 * (category `i18n`, key = the message key, value = {"en": ..., "so": ...} with only the edited languages present), and the panel lays the overrides
 * over the catalogue. Deleting an override returns that message to the built-in wording. Reading is open to every signed-in admin (the panel needs
 * the wording to render); writing is Super Admin only (the `super_admin` middleware, checked against the role itself) and every change is audited.
 */
class TranslationOverrideController extends Controller
{
    public const CATEGORY = 'i18n';

    /** Upper bound on stored overrides, so the table cannot grow without limit. The built-in catalogue has about 3,200 messages. */
    public const MAX_OVERRIDES = 5000;

    public const MAX_TEXT_LENGTH = 1000;

    public function index(): JsonResponse
    {
        $overrides = SystemSetting::query()->category(self::CATEGORY)->orderBy('key')->get()
            ->mapWithKeys(fn (SystemSetting $row): array => [$row->key => $this->clean($row->value)])
            ->filter()
            ->all();

        return ApiResponse::success('Translation overrides retrieved successfully.', ['overrides' => (object) $overrides]);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $validated = validator(
            ['key' => $key, 'en' => $request->input('en'), 'so' => $request->input('so')],
            [
                'key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
                'en' => ['nullable', 'string', 'max:'.self::MAX_TEXT_LENGTH, 'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/u'],
                'so' => ['nullable', 'string', 'max:'.self::MAX_TEXT_LENGTH, 'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/u'],
            ],
        )->validate();

        // Only the languages that were sent and are not blank are edited; a blank one means "use the built-in wording" for that language.
        $value = array_filter([
            'en' => $this->normalise($validated['en'] ?? null),
            'so' => $this->normalise($validated['so'] ?? null),
        ], fn (?string $text): bool => $text !== null);

        if ($value === []) {
            return ApiResponse::error('Enter the English or the Somali wording to save. To go back to the built-in wording, reset the message instead.', 'VALIDATION_ERROR', 422, ['en' => ['The English or the Somali wording is required.']]);
        }

        /** @var Admin $actor */
        $actor = $request->user();

        return DB::transaction(function () use ($actor, $key, $value): JsonResponse {
            $row = SystemSetting::query()->category(self::CATEGORY)->where('key', $key)->lockForUpdate()->first();

            if ($row === null && SystemSetting::query()->category(self::CATEGORY)->count() >= self::MAX_OVERRIDES) {
                return ApiResponse::error('The limit of edited messages has been reached.', 'LIMIT_REACHED', 422);
            }

            $before = $row === null ? null : $this->clean($row->value);

            $row ??= new SystemSetting(['category' => self::CATEGORY, 'key' => $key, 'is_sensitive' => false]);
            $row->fill(['value' => $value, 'updated_by' => $actor->id])->save();

            event(AuditEvent::record(
                action: $before === null ? AuditAction::Create : AuditAction::Update,
                admin: $actor,
                description: "Translation wording edited for \"{$key}\".",
                entityType: SystemSetting::class,
                entityId: $row->id,
                metadata: ['key' => $key, 'before' => $before, 'after' => $value],
            ));

            return ApiResponse::success('Translation saved successfully.', ['key' => $key, 'override' => $value]);
        });
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        validator(['key' => $key], ['key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/']])->validate();

        /** @var Admin $actor */
        $actor = $request->user();

        return DB::transaction(function () use ($actor, $key): JsonResponse {
            $row = SystemSetting::query()->category(self::CATEGORY)->where('key', $key)->lockForUpdate()->first();

            if ($row === null) {
                return ApiResponse::success('Translation is already using the built-in wording.', ['key' => $key]);
            }

            $before = $this->clean($row->value);
            $rowId = $row->id;
            $row->delete();

            event(AuditEvent::record(
                action: AuditAction::Delete,
                admin: $actor,
                description: "Translation wording reset to the built-in wording for \"{$key}\".",
                entityType: SystemSetting::class,
                entityId: $rowId,
                metadata: ['key' => $key, 'before' => $before, 'after' => null],
            ));

            return ApiResponse::success('Translation reset successfully.', ['key' => $key]);
        });
    }

    private function normalise(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * @return array{en?: string, so?: string}|null
     */
    private function clean(mixed $value): ?array
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
