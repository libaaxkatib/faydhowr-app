<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\Facility;

use App\Enums\FacilityComplaintStatus;
use App\Enums\FacilityComplaintType;
use App\Enums\FacilitySatisfaction;
use App\Support\Hr\Facility\FacilityCalendar;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The staff and customer complaints recorded INSIDE an inspection. The inspector must say, for each kind, either "no complaint" or "there is
 * one" (and then describe it completely): an incomplete complaint is refused rather than saved as if it were whole. Satisfaction is only
 * accepted together with written evidence and is never inferred from the status or from the response.
 */
final class FacilityComplaintRules
{
    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'staff_complaints_reported' => ['required', 'boolean'],
            'customer_complaints_reported' => ['required', 'boolean'],
            'complaints' => ['sometimes', 'array', 'max:50'],
            'complaints.*.type' => ['required', Rule::in(FacilityComplaintType::values())],
            'complaints.*.details' => ['required', 'string', 'min:5', 'max:5000'],
            'complaints.*.reporter_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'complaints.*.reporter_name' => ['nullable', 'string', 'max:150'],
            'complaints.*.reporter_type' => ['nullable', 'string', 'max:60'],
            'complaints.*.reporter_phone' => ['nullable', 'string', 'max:40'],
            'complaints.*.responsible_person' => ['required', 'string', 'max:150'],
            'complaints.*.response' => ['nullable', 'string', 'max:5000'],
            // Complaints entered INSIDE the inspection form default to the inspection date, so they follow the same Mogadishu "today" as the inspection
            // (the Complaints screen's own requests are unchanged).
            'complaints.*.reported_on' => ['nullable', 'date', 'before_or_equal:'.FacilityCalendar::today()->toDateString()],
            'complaints.*.responded_on' => ['nullable', 'date', 'before_or_equal:'.FacilityCalendar::today()->toDateString()],
            'complaints.*.corrective_action' => ['nullable', 'string', 'max:2000'],
            'complaints.*.follow_up_on' => ['nullable', 'date'],
            'complaints.*.status' => ['sometimes', Rule::in(FacilityComplaintStatus::values())],
            'complaints.*.status_note' => ['nullable', 'string', 'max:2000'],
            'complaints.*.satisfaction' => ['nullable', Rule::in(FacilitySatisfaction::values())],
            'complaints.*.satisfaction_evidence' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The rules that depend on other fields. Messages name the complaint ("Staff complaint 1") so the form can show them next to it.
     *
     * @param  array<int, array<string, mixed>>|null  $complaints
     */
    public static function check(Validator $validator, ?array $complaints, mixed $staffReported, mixed $customerReported, ?string $inspectedOn): void
    {
        $complaints = is_array($complaints) ? $complaints : [];
        $counts = ['staff' => 0, 'customer' => 0];

        foreach ($complaints as $index => $c) {
            if (! is_array($c)) {
                continue;
            }
            $type = (string) ($c['type'] ?? '');
            if (isset($counts[$type])) {
                $counts[$type]++;
            }
            $status = (string) ($c['status'] ?? 'open');
            $on = (string) ($c['reported_on'] ?? $inspectedOn ?? '');
            $filled = fn (string $key): bool => isset($c[$key]) && trim((string) $c[$key]) !== '';
            $add = fn (string $field, string $message) => $validator->errors()->add("complaints.{$index}.{$field}", $message);

            if ($type === 'staff' && empty($c['reporter_employee_id'])) {
                $add('reporter_employee_id', 'Choose the employee who made the staff complaint.');
            }
            if ($type === 'customer' && ! $filled('reporter_name')) {
                $add('reporter_name', 'Enter the name of the customer who complained.');
            }
            if ($type === 'customer' && ! empty($c['reporter_employee_id'])) {
                $add('reporter_employee_id', 'A customer complaint is not raised by an employee. Record the customer\'s details instead.');
            }
            if (in_array($status, ['answered', 'resolved'], true) && ! $filled('response')) {
                $add('response', 'A complaint that is answered or resolved needs the response that was given.');
            }
            if (in_array($status, ['answered', 'resolved'], true) && ! $filled('corrective_action')) {
                $add('corrective_action', 'Write the corrective action taken or agreed.');
            }
            if ($status === 'resolved' && (! $filled('status_note') || mb_strlen(trim((string) $c['status_note'])) < 5)) {
                $add('status_note', 'Say how the complaint was resolved (at least a few words).');
            }
            if ($filled('responded_on') && $on !== '' && (string) $c['responded_on'] < $on) {
                $add('responded_on', 'The response date cannot be before the complaint date.');
            }
            if ($filled('follow_up_on') && $on !== '' && (string) $c['follow_up_on'] < $on) {
                $add('follow_up_on', 'The follow-up date cannot be before the complaint date.');
            }
            if (! empty($c['satisfaction']) && (! $filled('satisfaction_evidence') || mb_strlen(trim((string) $c['satisfaction_evidence'])) < 10)) {
                $add('satisfaction_evidence', 'Describe the documented feedback (who said it, how and when) - at least 10 characters.');
            }
            if (empty($c['satisfaction']) && $filled('satisfaction_evidence')) {
                $add('satisfaction', 'Choose what the feedback was, or remove the evidence text.');
            }
        }

        foreach (['staff' => $staffReported, 'customer' => $customerReported] as $type => $reported) {
            $label = $type === 'staff' ? 'staff' : 'customer';
            if ($reported === null) {
                continue;
            }
            if (filter_var($reported, FILTER_VALIDATE_BOOLEAN) && $counts[$type] === 0) {
                $validator->errors()->add("{$label}_complaints_reported", "You said there is a {$label} complaint - describe it, or choose \"No {$label} complaint\".");
            }
            if (! filter_var($reported, FILTER_VALIDATE_BOOLEAN) && $counts[$type] > 0) {
                $validator->errors()->add("{$label}_complaints_reported", "\"No {$label} complaint\" is chosen but a {$label} complaint was entered. Choose that there is one, or remove it.");
            }
        }
    }
}
