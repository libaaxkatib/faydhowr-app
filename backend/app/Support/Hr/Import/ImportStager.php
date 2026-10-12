<?php

namespace App\Support\Hr\Import;

use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Enums\ImportType;
use App\Models\Admin;
use App\Models\ImportBatch;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Upload step: validates the workbook, classifies every row (writing nothing to the business tables) and
 * stores the rows as staging data for the preview. The uploaded file itself is read from PHP's temporary
 * upload and is never stored. Staging personal data is purged after RETENTION_DAYS.
 */
final class ImportStager
{
    /** Retention of staging personal data, counted from the upload in UTC. */
    public const RETENTION_DAYS = 30;

    public function __construct(
        private readonly WorkbookReader $reader,
        private readonly ImportRegistry $registry,
    ) {}

    /**
     * @return array{batch: ImportBatch, unrecognised_headers: list<string>}
     *
     * @throws ImportFileException
     * @throws ImportDomainException
     */
    public function stage(UploadedFile $file, ImportType $type, Admin $actor): array
    {
        $handler = $this->registry->for($type);
        $size = (int) $file->getSize();
        $extension = $this->reader->extension($file);

        // Cheap, friendly repeat check before any parsing. The partial unique index is the real guarantee
        // (it also stops two simultaneous uploads of the same file).
        $sha = is_string($file->getRealPath()) && is_file($file->getRealPath()) ? hash_file('sha256', $file->getRealPath()) : '';
        $this->assertNotRepeated($type, $sha);

        $parsed = $this->reader->read($file, $handler->columns(), $handler->requiredColumns(), $handler->ignoredColumns());

        $seen = [];
        $staged = [];
        $counts = ['valid' => 0, 'invalid' => 0, 'duplicate' => 0, 'review' => 0];

        foreach ($parsed->rows as $row) {
            $verdict = $handler->stage($row['cells'], $seen);
            $staged[] = [$row['row_number'], $verdict];
            $counts[$verdict->status->value]++;
        }

        $now = CarbonImmutable::now('UTC');

        try {
            $batch = DB::transaction(function () use ($type, $actor, $sha, $size, $extension, $staged, $counts, $now): ImportBatch {
                $batch = ImportBatch::query()->create([
                    'type' => $type,
                    'status' => ImportBatchStatus::Staged,
                    'created_by' => $actor->id,
                    'source_sha256' => $sha,
                    'source_size' => $size,
                    'source_extension' => $extension,
                    'total_rows' => count($staged),
                    'valid_rows' => $counts['valid'],
                    'invalid_rows' => $counts['invalid'],
                    'duplicate_rows' => $counts['duplicate'],
                    'review_rows' => $counts['review'],
                    'retention_expires_at' => $now->addDays(self::RETENTION_DAYS),
                ]);

                foreach (array_chunk($staged, 200) as $chunk) {
                    $batch->rows()->insert(array_map(fn (array $item): array => $this->rowRecord($batch->id, $item[0], $item[1], $now), $chunk));
                }

                return $batch;
            });
        } catch (QueryException $e) {
            // Two uploads of the same file raced: the unique index let exactly one through.
            if ($this->isUniqueViolation($e)) {
                $this->assertNotRepeated($type, $sha);
            }

            throw $e;
        }

        return ['batch' => $batch, 'unrecognised_headers' => $parsed->unrecognisedHeaders];
    }

    private function assertNotRepeated(ImportType $type, string $sha): void
    {
        if ($type->isPreviewOnly() || $sha === '') {
            return;
        }

        $existing = ImportBatch::query()
            ->where('type', $type->value)
            ->where('source_sha256', $sha)
            ->whereIn('status', [ImportBatchStatus::Staged->value, ImportBatchStatus::Processing->value, ImportBatchStatus::Completed->value])
            ->first();

        if ($existing !== null) {
            throw new ImportDomainException(
                'DUPLICATE_IMPORT',
                'This exact file has already been uploaded for this import type. Open its existing batch instead of importing it again.',
                409,
                ['existing_batch_id' => $existing->id, 'existing_status' => $existing->status->value],
            );
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? '');

        return $state === '23505' || $state === '23000' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * @return array<string, mixed>
     */
    private function rowRecord(int $batchId, int $rowNumber, StagedRow $verdict, CarbonImmutable $now): array
    {
        return [
            'import_batch_id' => $batchId,
            'row_number' => $rowNumber,
            'status' => $verdict->status->value,
            'code' => $verdict->code,
            'message' => mb_substr($verdict->message, 0, 500),
            'payload' => $verdict->payload === null ? null : json_encode($verdict->payload, JSON_UNESCAPED_UNICODE),
            'name_key' => $verdict->nameKey === null ? null : mb_substr($verdict->nameKey, 0, 255),
            'phone_key' => $verdict->phoneKey === null ? null : mb_substr($verdict->phoneKey, 0, 20),
            'meta' => $verdict->meta === null ? null : json_encode($verdict->meta, JSON_UNESCAPED_UNICODE),
            'entity_type' => $verdict->entityType,
            'entity_id' => $verdict->entityId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /** Statuses a row can be staged with. */
    public static function stagingStatuses(): array
    {
        return [ImportRowStatus::Valid, ImportRowStatus::Invalid, ImportRowStatus::Duplicate, ImportRowStatus::Review];
    }
}
