<?php

namespace App\Http\Controllers\Api\V1\Payment;

use App\Actions\Customer\GetCustomerProfileAction;
use App\Actions\Payment\GetCustomerPaymentAction;
use App\Actions\Payment\InitializePaymentAction;
use App\Actions\Payment\ListCustomerPaymentsAction;
use App\Actions\Payment\ProcessPaymentAction;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Payment\InitializePaymentRequest;
use App\Http\Resources\Api\V1\Payment\PaymentResource;
use App\Models\User;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PaymentController extends Controller
{
    public function index(
        Request $request,
        GetCustomerProfileAction $getCustomerProfile,
        ListCustomerPaymentsAction $listCustomerPayments,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $status = $this->requestedStatus($request);

        if ($status === false) {
            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                ['status' => ['The selected status is invalid.']],
            );
        }

        $payableType = $this->requestedPayableType($request);

        if ($payableType === false) {
            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                ['payable_type' => ['The selected payable type is invalid.']],
            );
        }

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $payments = $listCustomerPayments->handle(
                $profile,
                $status,
                $this->requestedGateway($request),
                $payableType,
                min(max($request->integer('per_page', 15), 1), 100),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve payments.',
                'PAYMENTS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Payments retrieved successfully.',
            [
                'items' => PaymentResource::collection($payments->getCollection()),
                'pagination' => [
                    'current_page' => $payments->currentPage(),
                    'per_page' => $payments->perPage(),
                    'total' => $payments->total(),
                    'last_page' => $payments->lastPage(),
                ],
            ],
        );
    }

    public function show(
        Request $request,
        int $payment,
        GetCustomerProfileAction $getCustomerProfile,
        GetCustomerPaymentAction $getCustomerPayment,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $customerPayment = $getCustomerPayment->handle($profile, $payment);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve payment.',
                'PAYMENT_FETCH_FAILED',
                500,
            );
        }

        if ($customerPayment === null) {
            return ApiResponse::error(
                'Payment not found.',
                'PAYMENT_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Payment retrieved successfully.',
            new PaymentResource($customerPayment),
        );
    }

    public function initialize(
        InitializePaymentRequest $request,
        GetCustomerProfileAction $getCustomerProfile,
        InitializePaymentAction $initializePayment,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $payment = $initializePayment->handle($profile, $request->validated());
        } catch (ModelNotFoundException) {
            return ApiResponse::error(
                'Order not found.',
                'ORDER_NOT_FOUND',
                404,
            );
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to initialize payment.',
                'PAYMENT_INITIALIZATION_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Payment initialized successfully.',
            new PaymentResource($payment),
            $payment->wasRecentlyCreated ? 201 : 200,
        );
    }

    public function process(
        Request $request,
        int $payment,
        GetCustomerProfileAction $getCustomerProfile,
        ProcessPaymentAction $processPayment,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $processedPayment = $processPayment->handle($profile, $payment);
        } catch (ModelNotFoundException) {
            return ApiResponse::error(
                'Payment not found.',
                'PAYMENT_NOT_FOUND',
                404,
            );
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to process payment.',
                'PAYMENT_PROCESSING_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Payment processing started successfully.',
            new PaymentResource($processedPayment),
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

    private function requestedStatus(Request $request): PaymentStatus|false|null
    {
        $status = $request->query('status');

        if ($status === null || $status === '') {
            return null;
        }

        return is_string($status) ? PaymentStatus::tryFrom($status) ?? false : false;
    }

    private function requestedGateway(Request $request): ?string
    {
        $gateway = $request->query('gateway');

        if ($gateway === null || $gateway === '') {
            return null;
        }

        return is_string($gateway) ? $gateway : null;
    }

    private function requestedPayableType(Request $request): string|false|null
    {
        $payableType = $request->query('payable_type');

        if ($payableType === null || $payableType === '') {
            return null;
        }

        if (! is_string($payableType)) {
            return false;
        }

        return in_array($payableType, ['order'], true) ? $payableType : false;
    }
}
