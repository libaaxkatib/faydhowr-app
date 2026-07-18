<?php

namespace App\Http\Controllers\Api\V1\Admin\Quotations;

use App\Actions\Quotation\AdminAcceptQuotationAction;
use App\Actions\Quotation\AdminCancelQuotationAction;
use App\Actions\Quotation\AssignQuotationReviewerAction;
use App\Actions\Quotation\CloseQuotationDiscussionAction;
use App\Actions\Quotation\CreateAdminQuotationDiscussionMessageAction;
use App\Actions\Quotation\ExpireQuotationAction;
use App\Actions\Quotation\GetAdminQuotationAction;
use App\Actions\Quotation\IssueQuotationRevisionAction;
use App\Actions\Quotation\ListAdminQuotationsAction;
use App\Contracts\Upload\Services\UploadServiceInterface;
use App\Exceptions\Quotation\QuotationInvalidStateException;
use App\Exceptions\Quotation\QuotationRevisionStaleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Quotations\AdminAcceptQuotationRequest;
use App\Http\Requests\Api\V1\Admin\Quotations\AdminQuotationDiscussionMessageRequest;
use App\Http\Requests\Api\V1\Admin\Quotations\AssignQuotationReviewerRequest;
use App\Http\Requests\Api\V1\Admin\Quotations\CancelQuotationRequest;
use App\Http\Requests\Api\V1\Admin\Quotations\IssueQuotationRevisionRequest;
use App\Http\Requests\Api\V1\Admin\Quotations\ListAdminQuotationsRequest;
use App\Http\Resources\Api\V1\Admin\Quotations\AdminQuotationResource;
use App\Http\Resources\Api\V1\Quotation\QuotationDiscussionMessageResource;
use App\Models\Admin;
use App\Models\Quotation;
use App\Models\QuotationAttachment;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class QuotationController extends Controller
{
    public function index(
        ListAdminQuotationsRequest $request,
        ListAdminQuotationsAction $listAdminQuotations,
    ): JsonResponse {
        $paginator = $listAdminQuotations->handle($request->toFilters());

        return ApiResponse::success(
            'Quotations retrieved successfully.',
            ['items' => AdminQuotationResource::collection($paginator->items())],
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function show(
        int $quotation,
        GetAdminQuotationAction $getAdminQuotation,
    ): JsonResponse {
        $detail = $getAdminQuotation->handle($quotation);

        if ($detail === null) {
            return $this->notFound();
        }

        return ApiResponse::success(
            'Quotation retrieved successfully.',
            new AdminQuotationResource($detail),
        );
    }

    public function downloadAttachment(
        int $quotation,
        int $attachment,
        UploadServiceInterface $uploads,
    ): StreamedResponse|JsonResponse {
        $upload = QuotationAttachment::query()
            ->where('quotation_id', $quotation)
            ->whereKey($attachment)
            ->with('upload')
            ->first()
            ?->upload;

        if ($upload === null) {
            return ApiResponse::error(
                'Quotation attachment not found.',
                'QUOTATION_ATTACHMENT_NOT_FOUND',
                404,
            );
        }

        return $uploads->stream($upload);
    }

    public function assign(
        AssignQuotationReviewerRequest $request,
        int $quotation,
        AssignQuotationReviewerAction $assignQuotationReviewer,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $assigned = $assignQuotationReviewer->handle($admin, $quotation, $request->assignedAdminId());
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (QuotationInvalidStateException $exception) {
            return ApiResponse::error($exception->getMessage(), 'QUOTATION_INVALID_STATE', 409);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Failed to assign quotation reviewer.', 'QUOTATION_ASSIGN_FAILED', 500);
        }

        return ApiResponse::success(
            'Quotation reviewer assigned successfully.',
            new AdminQuotationResource($assigned),
        );
    }

    public function issue(
        IssueQuotationRevisionRequest $request,
        int $quotation,
        IssueQuotationRevisionAction $issueQuotationRevision,
    ): JsonResponse {
        return $this->issueRevision($request, $quotation, $issueQuotationRevision, initial: true);
    }

    public function storeRevision(
        IssueQuotationRevisionRequest $request,
        int $quotation,
        IssueQuotationRevisionAction $issueQuotationRevision,
    ): JsonResponse {
        return $this->issueRevision($request, $quotation, $issueQuotationRevision, initial: false);
    }

    public function storeDiscussionMessage(
        AdminQuotationDiscussionMessageRequest $request,
        int $quotation,
        CreateAdminQuotationDiscussionMessageAction $createDiscussionMessage,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $message = $createDiscussionMessage->handle($admin, $quotation, $request->messageBody());
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (QuotationInvalidStateException $exception) {
            return ApiResponse::error($exception->getMessage(), 'QUOTATION_INVALID_STATE', 409);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Failed to create quotation discussion message.', 'QUOTATION_DISCUSSION_CREATE_FAILED', 500);
        }

        return ApiResponse::success(
            'Quotation discussion message created successfully.',
            new QuotationDiscussionMessageResource($message),
            201,
        );
    }

    public function closeDiscussion(
        Request $request,
        int $quotation,
        CloseQuotationDiscussionAction $closeQuotationDiscussion,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        return $this->transition(
            fn () => $closeQuotationDiscussion->handle($admin, $quotation),
            'Quotation discussion closed successfully.',
            'QUOTATION_CLOSE_DISCUSSION_FAILED',
            'Failed to close quotation discussion.',
        );
    }

    public function expire(
        Request $request,
        int $quotation,
        ExpireQuotationAction $expireQuotation,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        return $this->transition(
            fn () => $expireQuotation->handle($admin, $quotation),
            'Quotation expired successfully.',
            'QUOTATION_EXPIRE_FAILED',
            'Failed to expire quotation.',
        );
    }

    public function cancel(
        CancelQuotationRequest $request,
        int $quotation,
        AdminCancelQuotationAction $adminCancelQuotation,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        return $this->transition(
            fn () => $adminCancelQuotation->handle($admin, $quotation, $request->reason()),
            'Quotation cancelled successfully.',
            'QUOTATION_CANCEL_FAILED',
            'Failed to cancel quotation.',
        );
    }

    public function accept(
        AdminAcceptQuotationRequest $request,
        int $quotation,
        AdminAcceptQuotationAction $adminAcceptQuotation,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $accepted = $adminAcceptQuotation->handle($admin, $quotation, $request->revisionId(), $request->reason());
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (QuotationRevisionStaleException $exception) {
            return ApiResponse::error($exception->getMessage(), 'QUOTATION_REVISION_STALE', 409);
        } catch (QuotationInvalidStateException $exception) {
            return ApiResponse::error($exception->getMessage(), 'QUOTATION_INVALID_STATE', 409);
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Failed to accept quotation.', 'QUOTATION_ACCEPTANCE_FAILED', 500);
        }

        return ApiResponse::success(
            'Quotation accepted successfully.',
            new AdminQuotationResource($accepted),
        );
    }

    private function issueRevision(
        IssueQuotationRevisionRequest $request,
        int $quotation,
        IssueQuotationRevisionAction $issueQuotationRevision,
        bool $initial,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $issued = $issueQuotationRevision->handle($admin, $quotation, $request->toRevisionData(), $initial);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (QuotationInvalidStateException $exception) {
            return ApiResponse::error($exception->getMessage(), 'QUOTATION_INVALID_STATE', 409);
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Failed to issue quotation revision.', 'QUOTATION_ISSUE_FAILED', 500);
        }

        return ApiResponse::success(
            $initial ? 'Quotation issued successfully.' : 'Quotation revision issued successfully.',
            new AdminQuotationResource($issued),
            201,
        );
    }

    /**
     * @param  callable(): Quotation  $handler
     */
    private function transition(
        callable $handler,
        string $successMessage,
        string $failureCode,
        string $failureMessage,
    ): JsonResponse {
        try {
            $result = $handler();
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (QuotationInvalidStateException $exception) {
            return ApiResponse::error($exception->getMessage(), 'QUOTATION_INVALID_STATE', 409);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error($failureMessage, $failureCode, 500);
        }

        return ApiResponse::success($successMessage, new AdminQuotationResource($result));
    }

    private function notFound(): JsonResponse
    {
        return ApiResponse::error(
            'Quotation not found.',
            'QUOTATION_NOT_FOUND',
            404,
        );
    }
}
