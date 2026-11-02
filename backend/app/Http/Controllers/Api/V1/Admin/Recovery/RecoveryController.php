<?php

namespace App\Http\Controllers\Api\V1\Admin\Recovery;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\RecoveryCorrection;
use App\Support\ApiResponse;
use App\Support\Hr\Recovery\AssignmentRecovery;
use App\Support\Hr\Recovery\RecoveryErrorCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Super Admin Recovery Center (HR first). Every route sits behind `auth:sanctum`, `admin` and `super_admin`, and apply() checks the role again.
 * Reading never writes; preview rolls its own work back; apply is the only writer (see AssignmentRecovery).
 */
class RecoveryController extends Controller
{
    public function __construct(private readonly AssignmentRecovery $recovery) {}

    public function workflows(): JsonResponse
    {
        return $this->ok(AssignmentRecovery::catalogue(), 'Recovery workflows.');
    }

    public function assignments(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        if (mb_strlen($search) < 2 && ! ctype_digit($search)) {
            return $this->ok([], 'Type at least 2 characters.');
        }

        $rows = EmployeeWorkAssignment::query()
            ->with(['employee:id,employee_number,full_name,status', 'clientCompany:id,name'])
            ->where(function ($q) use ($search): void {
                if (ctype_digit($search)) {
                    $q->where('id', (int) $search);
                }

                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($search)).'%';
                $q->orWhereIn('employee_id', Employee::query()->withTrashed()->where(fn ($e) => $e->whereRaw('lower(full_name) like ?', [$like])->orWhereRaw('lower(employee_number) like ?', [$like]))->select('id'));
            })
            ->orderByDesc('id')->limit(40)->get();

        return $this->ok($rows->map(fn (EmployeeWorkAssignment $a): array => [
            'id' => $a->id, 'status' => $a->status->value, 'type' => $a->assignment_type?->value, 'start_date' => $a->start_date->toDateString(), 'end_date' => $a->end_date?->toDateString(),
            'employee_number' => $a->employee?->employee_number, 'employee_name' => $a->employee?->full_name, 'company' => $a->clientCompany?->name,
        ])->all(), 'Assignments.');
    }

    public function inspect(EmployeeWorkAssignment $assignment): JsonResponse
    {
        return $this->ok($this->recovery->inspect($assignment), 'Assignment overview.');
    }

    public function payrollPreview(Request $request): JsonResponse
    {
        $data = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'], 'employee_id' => ['nullable', 'integer', 'min:1']]);

        return $this->ok($this->recovery->draftPreview($data['month'], isset($data['employee_id']) ? [(int) $data['employee_id']] : []), 'Draft payroll preview (nothing saved).');
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $this->validated($request, false);
        $assignment = EmployeeWorkAssignment::query()->findOrFail($data['assignment_id']);
        $plan = $this->recovery->plan($data['workflow'], $assignment, $data['params'] ?? []);

        return $this->ok($this->recovery->preview($plan, $this->admin($request)), 'Preview only: nothing was changed.');
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $this->validated($request, true);
        $assignment = EmployeeWorkAssignment::query()->findOrFail($data['assignment_id']);

        $result = $this->recovery->apply($data['workflow'], $assignment, $data['params'] ?? [], $this->admin($request), $data['reason'], $data['idempotency_key'], $data['token']);

        return $this->ok($this->present($result['correction'], $result['replayed']), $result['replayed'] ? 'This correction was already applied; nothing was repeated.' : 'Correction applied.', $result['replayed'] ? 200 : 201);
    }

    public function corrections(Request $request): JsonResponse
    {
        $rows = RecoveryCorrection::query()->with('actor:id,full_name')
            ->when($request->filled('assignment_id'), fn ($q) => $q->where('subject_id', (int) $request->query('assignment_id')))
            ->when($request->filled('workflow'), fn ($q) => $q->where('workflow', (string) $request->query('workflow')))
            ->orderByDesc('id')->limit(100)->get();

        return $this->ok($rows->map(fn (RecoveryCorrection $c): array => $this->present($c, false))->all(), 'Recovery corrections.');
    }

    public function errors(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        return $this->ok(['entries' => RecoveryErrorCatalogue::all(), 'match' => $q === '' ? null : RecoveryErrorCatalogue::find($q)], 'Blocked-action explanations.');
    }

    private function ok(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return ApiResponse::success($message, $data, $status);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $apply): array
    {
        $rules = [
            'workflow' => ['required', Rule::in(AssignmentRecovery::MUTATING)],
            'assignment_id' => ['required', 'integer', 'min:1'],
            'params' => ['nullable', 'array'],
            'params.month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'params.start_date' => ['nullable', 'date_format:Y-m-d'],
            'params.end_date' => ['nullable', 'date_format:Y-m-d'],
            'params.absence_id' => ['nullable', 'integer', 'min:1'],
            'params.last_working_day' => ['nullable', 'date_format:Y-m-d'],
            'params.returned_on' => ['nullable', 'date_format:Y-m-d'],
        ];

        if ($apply) {
            $rules += [
                'reason' => ['required', 'string', 'min:10', 'max:1000'],
                'confirm' => ['required', 'accepted'],
                'idempotency_key' => ['required', 'string', 'min:8', 'max:80'],
                'token' => ['required', 'string', 'max:2000'],
            ];
        }

        return $request->validate($rules);
    }

    /** @return array<string, mixed> */
    private function present(RecoveryCorrection $c, bool $replayed): array
    {
        return [
            'id' => $c->id, 'uuid' => $c->uuid, 'workflow' => $c->workflow, 'month' => $c->month, 'assignment_id' => $c->subject_id, 'reason' => $c->reason,
            'applied_by' => $c->actor?->full_name, 'applied_at' => $c->created_at?->toIso8601String(), 'status' => $c->status,
            'changes' => $c->changes, 'impact' => $c->impact, 'replayed' => $replayed,
        ];
    }

    private function admin(Request $request): Admin
    {
        $user = $request->user();
        abort_unless($user instanceof Admin, 401);

        return $user;
    }
}
