<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AdminRole;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\SystemSetting;
use App\Support\ApiResponse;
use App\Support\I18n\LockedTranslations;
use App\Support\I18n\TranslationOverrides;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Governance of Somali / English wording changes (owner decision TRANSLATION-POLICY, 2026-11-06):
 *
 *  - the HR Manager and the Marketing Manager may PROPOSE a change to the wording of a message;
 *  - only the Super Admin may APPROVE (or reject) a proposal;
 *  - a proposal never replaces the wording in use. The wording changes only when a Super Admin approves it, and then through the same override as the
 *    Super Admin editor (TranslationOverrides::save). Nothing here edits the built-in catalogue, the pinned approved messages or any approval record.
 *
 * A proposal is one row of `system_settings` (category `i18n_proposal`, key `p-<uuid>`, value = the proposal), so no table is added. Decided proposals are
 * kept (status approved / rejected / withdrawn, who and when); every step is audited.
 */
class TranslationProposalController extends Controller
{
    public const CATEGORY = 'i18n_proposal';

    public const MAX_PROPOSALS = 5000;

    public const MAX_LISTED = 300;

    private const PROPOSER_ROLES = [AdminRole::HrManager, AdminRole::MarketingManager];

    public function index(Request $request): JsonResponse
    {
        /** @var Admin $actor */
        $actor = $request->user();

        if (! $this->isSuperAdmin($actor) && ! $this->canPropose($actor)) {
            return $this->notAllowed('Only the Super Admin, the HR Manager and the Marketing Manager can see translation proposals.');
        }

        $rows = SystemSetting::query()->category(self::CATEGORY)->orderByDesc('id')->get()
            ->filter(fn (SystemSetting $row): bool => $this->isSuperAdmin($actor) || (int) ($row->value['proposed_by'] ?? 0) === $actor->id)
            ->filter(fn (SystemSetting $row): bool => ! $request->filled('status') || ($row->value['status'] ?? null) === $request->query('status'))
            ->take(self::MAX_LISTED)
            ->map(fn (SystemSetting $row): array => $this->present($row))
            ->values();

        return ApiResponse::success('Translation proposals retrieved successfully.', ['proposals' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var Admin $actor */
        $actor = $request->user();

        if (! $this->canPropose($actor)) {
            return $this->notAllowed('Only the HR Manager and the Marketing Manager can propose a wording change. The Super Admin edits the wording directly.');
        }

        $validated = validator($request->all(), [
            'key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]*$/'],
            'en' => ['nullable', 'string', 'max:'.TranslationOverrideController::MAX_TEXT_LENGTH, 'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/u'],
            'so' => ['nullable', 'string', 'max:'.TranslationOverrideController::MAX_TEXT_LENGTH, 'regex:/^[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*$/u'],
            'reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        // Owner-approved wording is locked: it cannot be proposed for change here, so no pending proposal can ever target it.
        if (LockedTranslations::isLocked($validated['key'])) {
            return LockedTranslations::refusal($validated['key']);
        }

        $wording = array_filter([
            'en' => $this->normalise($validated['en'] ?? null),
            'so' => $this->normalise($validated['so'] ?? null),
        ], fn (?string $text): bool => $text !== null);

        if ($wording === []) {
            return ApiResponse::error('Enter the English or the Somali wording to propose.', 'VALIDATION_ERROR', 422, ['en' => ['The English or the Somali wording is required.']]);
        }

        return DB::transaction(function () use ($actor, $validated, $wording): JsonResponse {
            if (SystemSetting::query()->category(self::CATEGORY)->count() >= self::MAX_PROPOSALS) {
                return ApiResponse::error('The limit of stored proposals has been reached.', 'LIMIT_REACHED', 422);
            }

            $pending = SystemSetting::query()->category(self::CATEGORY)->get()->first(
                fn (SystemSetting $row): bool => ($row->value['status'] ?? null) === 'pending'
                    && ($row->value['message_key'] ?? null) === $validated['key']
                    && (int) ($row->value['proposed_by'] ?? 0) === $actor->id,
            );

            if ($pending !== null) {
                return ApiResponse::error('You already have a pending proposal for this message. Withdraw it first to propose different wording.', 'PROPOSAL_ALREADY_PENDING', 422);
            }

            $row = SystemSetting::query()->create([
                'category' => self::CATEGORY,
                'key' => 'p-'.Str::uuid()->toString(),
                'is_sensitive' => false,
                'updated_by' => $actor->id,
                'value' => [
                    'message_key' => $validated['key'],
                    ...$wording,
                    'reason' => $this->normalise($validated['reason'] ?? null),
                    'status' => 'pending',
                    'proposed_by' => $actor->id,
                    'proposed_by_name' => $actor->full_name,
                    'proposed_by_role' => $actor->role->value,
                    'proposed_at' => Carbon::now()->toIso8601String(),
                ],
            ]);

            event(AuditEvent::record(
                action: AuditAction::Create,
                admin: $actor,
                description: "Translation change proposed for \"{$validated['key']}\" (not in use until a Super Admin approves it).",
                entityType: SystemSetting::class,
                entityId: $row->id,
                metadata: ['event' => 'translation_proposed', 'key' => $validated['key'], 'proposed' => $wording],
            ));

            return ApiResponse::success('Proposal sent. The wording in use does not change until a Super Admin approves it.', $this->present($row), 201);
        });
    }

    public function approve(Request $request, int $proposal): JsonResponse
    {
        /** @var Admin $actor */
        $actor = $request->user();

        if (! $this->isSuperAdmin($actor)) {
            return $this->notAllowed('Only the Super Admin can approve a translation proposal.');
        }

        return DB::transaction(function () use ($actor, $proposal): JsonResponse {
            $row = SystemSetting::query()->category(self::CATEGORY)->whereKey($proposal)->lockForUpdate()->first();

            if ($row === null) {
                return ApiResponse::error('Translation proposal not found.', 'NOT_FOUND', 404);
            }

            if (($row->value['status'] ?? null) !== 'pending') {
                return ApiResponse::error('This proposal has already been decided or withdrawn.', 'PROPOSAL_NOT_PENDING', 422);
            }

            $key = (string) $row->value['message_key'];

            // Super Admin approval does not extend to owner-locked wording: such a proposal can only be rejected (nothing is written, it stays pending).
            if (LockedTranslations::isLocked($key)) {
                return LockedTranslations::refusal($key);
            }

            $wording = array_filter(['en' => $row->value['en'] ?? null, 'so' => $row->value['so'] ?? null], fn ($text) => is_string($text) && $text !== '');

            // The approved wording becomes the override of that message - the same write as the Super Admin editor.
            $saved = TranslationOverrides::save($actor, $key, $wording, "Translation wording edited for \"{$key}\" by approving a proposal.", ['event' => 'translation_proposal_approved', 'proposal_id' => $row->id, 'proposed_by' => $row->value['proposed_by'] ?? null]);

            if ($saved === null) {
                return ApiResponse::error('The limit of edited messages has been reached.', 'LIMIT_REACHED', 422);
            }

            $this->decide($row, 'approved', $actor, null);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Translation proposal approved for \"{$key}\".",
                entityType: SystemSetting::class,
                entityId: $row->id,
                metadata: ['event' => 'translation_proposal_decided', 'decision' => 'approved', 'key' => $key, 'approved' => $wording, 'proposed_by' => $row->value['proposed_by'] ?? null],
            ));

            return ApiResponse::success('Proposal approved. The new wording is now in use.', $this->present($row->refresh()));
        });
    }

    public function reject(Request $request, int $proposal): JsonResponse
    {
        /** @var Admin $actor */
        $actor = $request->user();

        if (! $this->isSuperAdmin($actor)) {
            return $this->notAllowed('Only the Super Admin can reject a translation proposal.');
        }

        $note = $this->normalise(validator($request->all(), ['note' => ['nullable', 'string', 'max:500']])->validate()['note'] ?? null);

        return $this->close($proposal, 'rejected', $actor, $note, 'Proposal rejected. The wording in use is unchanged.');
    }

    public function withdraw(Request $request, int $proposal): JsonResponse
    {
        /** @var Admin $actor */
        $actor = $request->user();

        $row = SystemSetting::query()->category(self::CATEGORY)->whereKey($proposal)->first();

        if ($row === null || (int) ($row->value['proposed_by'] ?? 0) !== $actor->id) {
            return ApiResponse::error('Translation proposal not found.', 'NOT_FOUND', 404);
        }

        return $this->close($proposal, 'withdrawn', $actor, null, 'Proposal withdrawn.');
    }

    private function close(int $proposal, string $status, Admin $actor, ?string $note, string $message): JsonResponse
    {
        return DB::transaction(function () use ($proposal, $status, $actor, $note, $message): JsonResponse {
            $row = SystemSetting::query()->category(self::CATEGORY)->whereKey($proposal)->lockForUpdate()->first();

            if ($row === null) {
                return ApiResponse::error('Translation proposal not found.', 'NOT_FOUND', 404);
            }

            if (($row->value['status'] ?? null) !== 'pending') {
                return ApiResponse::error('This proposal has already been decided or withdrawn.', 'PROPOSAL_NOT_PENDING', 422);
            }

            $this->decide($row, $status, $actor, $note);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Translation proposal {$status} for \"{$row->value['message_key']}\".",
                entityType: SystemSetting::class,
                entityId: $row->id,
                metadata: ['event' => 'translation_proposal_decided', 'decision' => $status, 'key' => $row->value['message_key'], 'note' => $note, 'proposed_by' => $row->value['proposed_by'] ?? null],
            ));

            return ApiResponse::success($message, $this->present($row->refresh()));
        });
    }

    private function decide(SystemSetting $row, string $status, Admin $actor, ?string $note): void
    {
        $row->fill(['value' => [
            ...$row->value,
            'status' => $status,
            'decided_by' => $actor->id,
            'decided_by_name' => $actor->full_name,
            'decided_at' => Carbon::now()->toIso8601String(),
            'decision_note' => $note,
        ], 'updated_by' => $actor->id])->save();
    }

    /** @return array<string, mixed> */
    private function present(SystemSetting $row): array
    {
        $v = $row->value;

        return [
            'id' => $row->id,
            'message_key' => $v['message_key'] ?? null,
            'en' => $v['en'] ?? null,
            'so' => $v['so'] ?? null,
            'reason' => $v['reason'] ?? null,
            'status' => $v['status'] ?? null,
            'proposed_by' => ['id' => $v['proposed_by'] ?? null, 'name' => $v['proposed_by_name'] ?? null, 'role' => $v['proposed_by_role'] ?? null],
            'proposed_at' => $v['proposed_at'] ?? null,
            'decided_by' => isset($v['decided_by']) ? ['id' => $v['decided_by'], 'name' => $v['decided_by_name'] ?? null] : null,
            'decided_at' => $v['decided_at'] ?? null,
            'decision_note' => $v['decision_note'] ?? null,
        ];
    }

    private function canPropose(Admin $actor): bool
    {
        return in_array($actor->role, self::PROPOSER_ROLES, true);
    }

    private function isSuperAdmin(Admin $actor): bool
    {
        return $actor->role === AdminRole::SuperAdmin;
    }

    private function notAllowed(string $message): JsonResponse
    {
        return ApiResponse::error($message, 'TRANSLATION_PROPOSAL_NOT_ALLOWED', 403);
    }

    private function normalise(mixed $text): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $text = trim($text);

        return $text === '' ? null : $text;
    }
}
