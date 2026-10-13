<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Enums\AuditAction;
use App\Enums\ImportRowStatus;
use App\Enums\ImportType;
use App\Events\Audit\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StoreImportRequest;
use App\Http\Resources\Api\V1\Admin\Hr\ImportBatchResource;
use App\Http\Resources\Api\V1\Admin\Hr\ImportRowResource;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Support\ApiResponse;
use App\Support\Hr\Import\ImportDomainException;
use App\Support\Hr\Import\ImportFileException;
use App\Support\Hr\Import\ImportProcessor;
use App\Support\Hr\Import\ImportReport;
use App\Support\Hr\Import\ImportRetention;
use App\Support\Hr\Import\ImportStager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR Excel imports. Every route is reserved for Super Admin (route middleware: super_admin and
 * permission:hr.import). Workflow: upload (stages and previews) -> confirm -> process (repeat until
 * complete) -> report. Nothing is written to employees or companies before confirm.
 */
class ImportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $paginator = ImportBatch::query()
            ->with('createdBy')
            ->when($request->filled('type') && in_array($request->string('type')->toString(), ImportType::values(), true), fn ($q) => $q->where('type', $request->string('type')->toString()))
            ->orderByDesc('id')
            ->paginate($perPage);

        return ApiResponse::success(
            'Import history retrieved successfully.',
            ImportBatchResource::collection($paginator->items()),
            200,
            ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        );
    }

    public function store(StoreImportRequest $request, ImportStager $stager, ImportRetention $retention): JsonResponse
    {
        // Housekeeping that does not depend on the scheduler: remove expired staging data (bounded).
        $retention->purgeExpired(5);

        try {
            $result = $stager->stage($request->file('file'), ImportType::from($request->validated('type')), $request->user());
        } catch (ImportFileException $e) {
            return ApiResponse::error($e->getMessage(), $e->errorCode, 422);
        } catch (ImportDomainException $e) {
            return ApiResponse::error($e->getMessage(), $e->errorCode, $e->httpStatus, $e->details ?: null);
        }

        $batch = $result['batch'];

        event(AuditEvent::record(
            action: AuditAction::Create,
            admin: $request->user(),
            description: "HR import #{$batch->id} ({$batch->type->value}) uploaded and staged.",
            entityType: ImportBatch::class,
            entityId: $batch->id,
            metadata: ['import_batch_id' => $batch->id, 'type' => $batch->type->value, 'total' => $batch->total_rows, 'valid' => $batch->valid_rows, 'invalid' => $batch->invalid_rows, 'duplicate' => $batch->duplicate_rows, 'review' => $batch->review_rows],
        ));

        return ApiResponse::success(
            'Workbook staged. Review the preview before confirming.',
            (new ImportBatchResource($batch->load('createdBy')))->withExtra(['unrecognised_headers' => $result['unrecognised_headers']] + $this->summary($batch)),
            201,
        );
    }

    public function show(int $id): JsonResponse
    {
        $importBatch = $this->find($id);

        return ApiResponse::success(
            'Import retrieved successfully.',
            (new ImportBatchResource($importBatch->load(['createdBy', 'confirmedBy'])))->withExtra($this->summary($importBatch)),
        );
    }

    public function rows(Request $request, int $id): JsonResponse
    {
        $importBatch = $this->find($id);
        $perPage = max(1, min((int) $request->integer('per_page', 50), 200));
        $status = $request->string('status')->toString();

        $paginator = ImportRow::query()
            ->where('import_batch_id', $importBatch->id)
            ->when(in_array($status, ImportRowStatus::values(), true), fn ($q) => $q->where('status', $status))
            ->orderBy('row_number')
            ->paginate($perPage);

        return ApiResponse::success(
            'Import rows retrieved successfully.',
            ImportRowResource::collection($paginator->items()),
            200,
            ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()],
        );
    }

    public function confirm(Request $request, int $id, ImportProcessor $processor): JsonResponse
    {
        $importBatch = $this->find($id);
        $request->validate(['confirm' => ['required', 'accepted']]);

        try {
            $batch = $processor->confirm($importBatch, $request->user());
        } catch (ImportDomainException $e) {
            return ApiResponse::error($e->getMessage(), $e->errorCode, $e->httpStatus);
        }

        return ApiResponse::success('Import confirmed.', (new ImportBatchResource($batch->load(['createdBy', 'confirmedBy'])))->withExtra($this->summary($batch)));
    }

    /** Imports the next bounded set of rows. The panel calls this until status is "completed". */
    public function process(Request $request, int $id, ImportProcessor $processor): JsonResponse
    {
        $importBatch = $this->find($id);
        try {
            $batch = $processor->processNext($importBatch, $request->user());
        } catch (ImportDomainException $e) {
            return ApiResponse::error($e->getMessage(), $e->errorCode, $e->httpStatus);
        }

        return ApiResponse::success('Import progress updated.', (new ImportBatchResource($batch->load(['createdBy', 'confirmedBy'])))->withExtra($this->summary($batch)));
    }

    public function cancel(Request $request, int $id, ImportProcessor $processor, ImportRetention $retention): JsonResponse
    {
        $importBatch = $this->find($id);
        try {
            $batch = $processor->cancel($importBatch, $request->user(), $retention);
        } catch (ImportDomainException $e) {
            return ApiResponse::error($e->getMessage(), $e->errorCode, $e->httpStatus);
        }

        return ApiResponse::success('Import cancelled and its staging data deleted.', new ImportBatchResource($batch->load('createdBy')));
    }

    public function report(Request $request, int $id, ImportReport $report): StreamedResponse
    {
        $importBatch = $this->find($id);
        $status = $request->string('status')->toString();

        return $report->response($importBatch, in_array($status, ImportRowStatus::values(), true) ? $status : null);
    }

    /**
     * The batch is looked up here, after the route middleware has already confirmed the caller is a Super Admin,
     * so an unauthorised caller always gets 403 and never learns which batch ids exist.
     */
    private function find(int $id): ImportBatch
    {
        return ImportBatch::query()->findOrFail($id);
    }

    /**
     * Extra, derived figures for the screen: rows still waiting, and for an activation preview the count
     * per transition path. Never includes personal data.
     *
     * @return array<string, mixed>
     */
    private function summary(ImportBatch $batch): array
    {
        $summary = [
            'pending_rows' => ImportRow::query()->where('import_batch_id', $batch->id)->where('status', ImportRowStatus::Valid->value)->count(),
        ];

        if ($batch->type === ImportType::ActivationPreview && ! $batch->isPurged()) {
            $paths = [];
            $warnings = [];
            $readyByPath = [];
            $excluded = [];

            ImportRow::query()->where('import_batch_id', $batch->id)->whereNotNull('meta')->chunkById(500, function ($rows) use (&$paths, &$warnings, &$readyByPath, &$excluded): void {
                foreach ($rows as $row) {
                    $path = $row->meta['path'] ?? ($row->meta['report']['proposed_path'] ?? null);

                    if ($path !== null) {
                        $paths[$path] = ($paths[$path] ?? 0) + 1;
                    }

                    foreach ((array) ($row->meta['warnings'] ?? []) as $warning) {
                        $warnings[$warning] = ($warnings[$warning] ?? 0) + 1;
                    }

                    // Activation-ready = valid. Review and invalid rows are EXCLUDED until corrected, with their reason.
                    if ($row->status === ImportRowStatus::Valid && $path !== null) {
                        $readyByPath[$path] = ($readyByPath[$path] ?? 0) + 1;
                    } elseif (in_array($row->status, [ImportRowStatus::Review, ImportRowStatus::Invalid], true)) {
                        $excluded[$row->code ?? 'UNKNOWN'] = ($excluded[$row->code ?? 'UNKNOWN'] ?? 0) + 1;
                    }
                }
            });

            $summary['activation_paths'] = $paths;
            $summary['activation_warnings'] = $warnings;
            $summary['activation_ready'] = array_sum($readyByPath);
            $summary['activation_ready_by_path'] = $readyByPath;
            $summary['activation_excluded'] = $excluded;
            $summary['activation_excluded_total'] = array_sum($excluded);
        }

        return $summary;
    }
}
