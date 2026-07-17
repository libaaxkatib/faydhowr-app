<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Contracts\Settings\Services\BackupServiceInterface;
use App\DataTransferObjects\Settings\BackupData;
use App\Exceptions\Settings\BackupNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Settings\RestoreBackupRequest;
use App\Models\SystemSetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    public function __construct(private BackupServiceInterface $backups) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', SystemSetting::class);

        return ApiResponse::success(
            'Backups retrieved successfully.',
            array_map(
                fn (BackupData $backup): array => $backup->toArray(),
                $this->backups->all(),
            ),
        );
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('update', SystemSetting::class);

        $backup = $this->backups->create($request->user(), $request->ip());

        return ApiResponse::success(
            'Backup created successfully.',
            $backup->toArray(),
            201,
        );
    }

    public function download(string $backup): StreamedResponse|JsonResponse
    {
        Gate::authorize('update', SystemSetting::class);

        try {
            return $this->backups->download($backup);
        } catch (BackupNotFoundException $exception) {
            return ApiResponse::error($exception->getMessage(), 'BACKUP_NOT_FOUND', 404);
        }
    }

    public function restore(RestoreBackupRequest $request, string $backup): JsonResponse
    {
        Gate::authorize('restoreBackup', SystemSetting::class);

        try {
            $this->backups->restore($backup, $request->user(), $request->ip());
        } catch (BackupNotFoundException $exception) {
            return ApiResponse::error($exception->getMessage(), 'BACKUP_NOT_FOUND', 404);
        }

        return ApiResponse::success('Backup restored successfully.');
    }
}
