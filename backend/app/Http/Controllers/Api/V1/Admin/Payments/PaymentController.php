<?php

namespace App\Http\Controllers\Api\V1\Admin\Payments;

use App\Actions\Payment\ConfirmOfflinePaymentAction;
use App\Actions\Payment\GetAdminPaymentAction;
use App\Actions\Payment\ListAdminPaymentsAction;
use App\Actions\Payment\RejectPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Payments\ConfirmPaymentRequest;
use App\Http\Requests\Api\V1\Admin\Payments\ListAdminPaymentsRequest;
use App\Http\Requests\Api\V1\Admin\Payments\RejectPaymentRequest;
use App\Http\Resources\Api\V1\Admin\Payments\AdminPaymentResource;
use App\Models\Admin;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Throwable;

class PaymentController extends Controller
{
    public function index(
        ListAdminPaymentsRequest $request,
        ListAdminPaymentsAction $listAdminPayments,
    ): JsonResponse {
        $paginator = $listAdminPayments->handle($request->toFilters());

        return ApiResponse::success(
            'Payments retrieved successfully.',
            ['items' => AdminPaymentResource::collection($paginator->items())],
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
        int $payment,
        GetAdminPaymentAction $getAdminPayment,
    ): JsonResponse {
        $found = $getAdminPayment->handle($payment);

        if ($found === null) {
            return $this->notFound();
        }

        return ApiResponse::success(
            'Payment retrieved successfully.',
            new AdminPaymentResource($found),
        );
    }

    public function confirm(
        ConfirmPaymentRequest $request,
        int $payment,
        ConfirmOfflinePaymentAction $confirmOfflinePayment,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $confirmed = $confirmOfflinePayment->handle($admin, $payment, $request->notes());
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'PAYMENT_STATE_INVALID', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to confirm payment.',
                'PAYMENT_CONFIRM_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Payment confirmed successfully.',
            new AdminPaymentResource($confirmed->load('customerProfile')),
        );
    }

    public function reject(
        RejectPaymentRequest $request,
        int $payment,
        RejectPaymentAction $rejectPayment,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $rejected = $rejectPayment->handle($admin, $payment, $request->reason());
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'PAYMENT_STATE_INVALID', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to reject payment.',
                'PAYMENT_REJECT_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Payment rejected successfully.',
            new AdminPaymentResource($rejected->load('customerProfile')),
        );
    }

    private function notFound(): JsonResponse
    {
        return ApiResponse::error('Payment not found.', 'PAYMENT_NOT_FOUND', 404);
    }
}
