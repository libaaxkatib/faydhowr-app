<?php

namespace App\Http\Controllers\Api\V1\Quotation;

use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\Quotation\AttachQuotationAttachmentsAction;
use App\Actions\Quotation\DetachQuotationAttachmentAction;
use App\Actions\Quotation\GetQuotationAttachmentAction;
use App\Contracts\Upload\Services\UploadServiceInterface;
use App\Exceptions\Quotation\QuotationAttachmentsLockedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Quotation\AttachQuotationUploadsRequest;
use App\Http\Resources\Api\V1\Quotation\QuotationResource;
use App\Models\User;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class QuotationAttachmentController extends Controller
{
    public function store(
        AttachQuotationUploadsRequest $request,
        int $quotation,
        GetCustomerProfileAction $getCustomerProfile,
        AttachQuotationAttachmentsAction $attachQuotationAttachments,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $updated = $attachQuotationAttachments->handle($profile, $quotation, $request->uploadUuids());
        } catch (ModelNotFoundException) {
            return $this->quotationNotFound();
        } catch (QuotationAttachmentsLockedException $exception) {
            return ApiResponse::error($exception->getMessage(), 'QUOTATION_ATTACHMENTS_LOCKED', 409);
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to attach uploads to quotation.',
                'QUOTATION_ATTACHMENT_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Uploads attached successfully.',
            new QuotationResource($updated),
            201,
        );
    }

    public function show(
        Request $request,
        int $quotation,
        int $attachment,
        GetCustomerProfileAction $getCustomerProfile,
        GetQuotationAttachmentAction $getQuotationAttachment,
        UploadServiceInterface $uploads,
    ): StreamedResponse|JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $upload = $getQuotationAttachment->handle($profile, $quotation, $attachment);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve quotation attachment.',
                'QUOTATION_ATTACHMENT_FETCH_FAILED',
                500,
            );
        }

        if ($upload === null) {
            return $this->attachmentNotFound();
        }

        return $uploads->stream($upload);
    }

    public function destroy(
        Request $request,
        int $quotation,
        int $attachment,
        GetCustomerProfileAction $getCustomerProfile,
        DetachQuotationAttachmentAction $detachQuotationAttachment,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $updated = $detachQuotationAttachment->handle($profile, $quotation, $attachment);
        } catch (ModelNotFoundException) {
            return $this->attachmentNotFound();
        } catch (QuotationAttachmentsLockedException $exception) {
            return ApiResponse::error($exception->getMessage(), 'QUOTATION_ATTACHMENTS_LOCKED', 409);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to detach quotation attachment.',
                'QUOTATION_ATTACHMENT_DETACH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Attachment detached successfully.',
            new QuotationResource($updated),
        );
    }

    private function profileNotFound(): JsonResponse
    {
        return ApiResponse::error(
            'Customer profile not found.',
            'CUSTOMER_PROFILE_NOT_FOUND',
            404,
        );
    }

    private function quotationNotFound(): JsonResponse
    {
        return ApiResponse::error(
            'Quotation not found.',
            'QUOTATION_NOT_FOUND',
            404,
        );
    }

    private function attachmentNotFound(): JsonResponse
    {
        return ApiResponse::error(
            'Quotation attachment not found.',
            'QUOTATION_ATTACHMENT_NOT_FOUND',
            404,
        );
    }
}
