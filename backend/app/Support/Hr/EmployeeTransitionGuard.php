<?php

namespace App\Support\Hr;

use App\Enums\AdminRole;
use App\Enums\EmployeeStatus;
use App\Models\Admin;
use App\Support\ApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Enforces the employee lifecycle on the backend (never only in the UI).
 *
 * The lifecycle is the one the product already uses (docs/HRM_MARKETING_SRS.md section 24 and the
 * panel's STATUS_FLOW): applicant -> recruitment -> practical -> waiting -> (approved) -> active,
 * with the implemented shortcut applicant -> waiting (a Practical "Approved" decision), separation
 * into inactive from any live status, and rehire from inactive back to active. Which transitions are
 * normal depends on the workflow that performs them (the "context"), because separation and rehire
 * also record their own reason/history.
 *
 * Any other transition is invalid by default. A Super Admin may override that, only through the
 * status-update workflow and only with a written reason; the reason, the actor and the previous and
 * new status are recorded by the caller on the status history and in the audit log. Nobody else can
 * override, and an override can never be used to "change" a status to the one it already has.
 */
final class EmployeeTransitionGuard
{
    public const CONTEXT_STATUS_UPDATE = 'status_update';

    public const CONTEXT_SEPARATION = 'separation';

    public const CONTEXT_REHIRE = 'rehire';

    public const CONTEXT_PRACTICAL_APPROVAL = 'practical_approval';

    public const CONTEXT_WAITING_IMPORT = 'waiting_import';

    public const DECISION_NORMAL = 'normal';

    public const DECISION_OVERRIDE_REQUIRED = 'override_required';

    public const DECISION_NO_CHANGE = 'no_change';

    public const MIN_REASON_LENGTH = 10;

    public const MAX_REASON_LENGTH = 500;

    /**
     * @return list<EmployeeStatus>
     */
    public function normalNext(EmployeeStatus $from, string $context = self::CONTEXT_STATUS_UPDATE): array
    {
        return match ($context) {
            self::CONTEXT_STATUS_UPDATE => match ($from) {
                EmployeeStatus::Applicant => [EmployeeStatus::Recruitment],
                EmployeeStatus::Recruitment => [EmployeeStatus::Practical],
                EmployeeStatus::Practical => [EmployeeStatus::Waiting],
                EmployeeStatus::Waiting => [EmployeeStatus::Approved, EmployeeStatus::Active],
                EmployeeStatus::Approved => [EmployeeStatus::Active],
                EmployeeStatus::Active, EmployeeStatus::Inactive => [],
            },
            self::CONTEXT_SEPARATION => $from === EmployeeStatus::Inactive ? [] : [EmployeeStatus::Inactive],
            self::CONTEXT_REHIRE => $from === EmployeeStatus::Inactive ? [EmployeeStatus::Active] : [],
            self::CONTEXT_PRACTICAL_APPROVAL => $from === EmployeeStatus::Applicant ? [EmployeeStatus::Waiting] : [],
            self::CONTEXT_WAITING_IMPORT => in_array($from, [EmployeeStatus::Applicant, EmployeeStatus::Recruitment, EmployeeStatus::Practical], true)
                ? [EmployeeStatus::Waiting]
                : [],
            default => [],
        };
    }

    /**
     * Side-effect-free classification, independent of who is acting. Used by dry runs and previews.
     *
     * @return self::DECISION_*
     */
    public function evaluate(EmployeeStatus $from, EmployeeStatus $to, string $context = self::CONTEXT_STATUS_UPDATE): string
    {
        if ($from === $to) {
            return self::DECISION_NO_CHANGE;
        }

        return in_array($to, $this->normalNext($from, $context), true)
            ? self::DECISION_NORMAL
            : self::DECISION_OVERRIDE_REQUIRED;
    }

    /**
     * Enforces the guard for a real change.
     *
     * @return string|null the override reason actually used (null when the transition was normal)
     *
     * @throws HttpResponseException
     */
    public function authorize(
        EmployeeStatus $from,
        EmployeeStatus $to,
        string $context,
        Admin $actor,
        ?string $overrideReason = null,
    ): ?string {
        $decision = $this->evaluate($from, $to, $context);

        if ($decision === self::DECISION_NO_CHANGE) {
            $this->fail("The employee is already {$from->label()}.", 'NO_STATUS_CHANGE');
        }

        if ($decision === self::DECISION_NORMAL) {
            return null;
        }

        $reason = $overrideReason === null ? '' : trim(preg_replace('/\s+/u', ' ', $overrideReason) ?? '');
        $isSuperAdmin = $actor->role === AdminRole::SuperAdmin;

        if (! $isSuperAdmin) {
            if ($reason !== '') {
                $this->fail('Only a Super Admin can override the employee lifecycle rules.', 'TRANSITION_OVERRIDE_FORBIDDEN', 403);
            }

            $this->fail($this->invalidMessage($from, $to, $context), 'INVALID_STATUS_TRANSITION');
        }

        if ($context !== self::CONTEXT_STATUS_UPDATE) {
            $this->fail($this->invalidMessage($from, $to, $context), 'INVALID_STATUS_TRANSITION');
        }

        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            $this->fail(
                $this->invalidMessage($from, $to, $context).' As a Super Admin you can override this, but a reason of at least '.self::MIN_REASON_LENGTH.' characters is required.',
                'TRANSITION_OVERRIDE_REASON_REQUIRED',
            );
        }

        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            $this->fail('The override reason may not be longer than '.self::MAX_REASON_LENGTH.' characters.', 'TRANSITION_OVERRIDE_REASON_TOO_LONG');
        }

        return $reason;
    }

    public function invalidMessage(EmployeeStatus $from, EmployeeStatus $to, string $context = self::CONTEXT_STATUS_UPDATE): string
    {
        $allowed = array_map(fn (EmployeeStatus $s): string => $s->label(), $this->normalNext($from, self::CONTEXT_STATUS_UPDATE));
        $hint = $allowed === [] ? 'No further status changes are allowed from here.' : 'Allowed next status: '.implode(' or ', $allowed).'.';

        if ($to === EmployeeStatus::Inactive) {
            $hint .= ' Use the separation workflow to make an employee inactive.';
        } elseif ($from === EmployeeStatus::Inactive) {
            $hint .= ' Use the rehire workflow to reactivate a separated employee.';
        }

        return "{$from->label()} cannot change to {$to->label()}. {$hint}";
    }

    private function fail(string $message, string $code, int $status = 422): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
