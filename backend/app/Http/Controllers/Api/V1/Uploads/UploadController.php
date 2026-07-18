<?php

namespace App\Http\Controllers\Api\V1\Uploads;

use App\Contracts\Upload\Services\UploadServiceInterface;
use App\Exceptions\Upload\InvalidImageFileException;
use App\Exceptions\Upload\InvalidPdfFileException;
use App\Exceptions\Upload\UploadAttachedException;
use App\Exceptions\Upload\UploadNotFoundException;
use App\Exceptions\Upload\UploadStorageLimitExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Uploads\ListUploadsRequest;
use App\Http\Requests\Api\V1\Uploads\StoreUploadRequest;
use App\Http\Resources\Api\V1\Uploads\UploadResource;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class UploadController extends Controller
{
    public function __construct(private UploadServiceInterface $uploads) {}

    public function store(StoreUploadRequest $request): JsonResponse
    {
        $profile = $this->resolveProfile($request);

        if ($profile === null) {
            return $this->profileNotFound();
        }

        try {
            $uploads = $this->uploads->store($profile, $request->uploadedFiles());
        } catch (InvalidImageFileException $exception) {
            return ApiResponse::error($exception->getMessage(), 'INVALID_IMAGE_FILE', 422);
        } catch (InvalidPdfFileException $exception) {
            return ApiResponse::error($exception->getMessage(), 'INVALID_PDF_FILE', 422);
        } catch (UploadStorageLimitExceededException $exception) {
            return ApiResponse::error(
                $exception->getMessage(),
                'UPLOAD_STORAGE_LIMIT_EXCEEDED',
                409,
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to store uploaded files.',
                'UPLOAD_STORE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Files uploaded successfully.',
            ['items' => UploadResource::collection($uploads)],
            201,
        );
    }

    public function index(ListUploadsRequest $request): JsonResponse
    {
        $profile = $this->resolveProfile($request);

        if ($profile === null) {
            return $this->profileNotFound();
        }

        $paginator = $this->uploads->listStaged($profile, $request->perPage());

        return ApiResponse::success(
            'Uploads retrieved successfully.',
            ['items' => UploadResource::collection($paginator->items())],
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function show(Request $request, string $uuid): StreamedResponse|JsonResponse
    {
        $profile = $this->resolveProfile($request);

        if ($profile === null) {
            return $this->profileNotFound();
        }

        try {
            $upload = $this->uploads->findForOwner($profile, $uuid);
        } catch (UploadNotFoundException) {
            return $this->uploadNotFound();
        }

        return $this->uploads->stream($upload);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $profile = $this->resolveProfile($request);

        if ($profile === null) {
            return $this->profileNotFound();
        }

        try {
            $this->uploads->delete($profile, $uuid);
        } catch (UploadNotFoundException) {
            return $this->uploadNotFound();
        } catch (UploadAttachedException) {
            return ApiResponse::error(
                'Attached uploads cannot be deleted.',
                'UPLOAD_ATTACHED',
                409,
            );
        }

        return ApiResponse::success('Upload deleted successfully.');
    }

    private function resolveProfile(Request $request): ?CustomerProfile
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->customerProfile;
    }

    private function profileNotFound(): JsonResponse
    {
        return ApiResponse::error(
            'Customer profile not found.',
            'CUSTOMER_PROFILE_NOT_FOUND',
            404,
        );
    }

    private function uploadNotFound(): JsonResponse
    {
        return ApiResponse::error('Upload not found.', 'NOT_FOUND', 404);
    }
}
