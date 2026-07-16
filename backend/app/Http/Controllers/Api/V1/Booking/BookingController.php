<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Actions\Booking\CreateBookingAction;
use App\Actions\Booking\CancelBookingAction;
use App\Actions\Booking\GetCustomerBookingAction;
use App\Actions\Booking\ListCustomerBookingsAction;
use App\Actions\Customer\GetCustomerProfileAction;
use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Booking\CancelBookingRequest;
use App\Http\Requests\Api\V1\Booking\StoreBookingRequest;
use App\Http\Resources\Api\V1\Booking\BookingResource;
use App\Models\User;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

class BookingController extends Controller
{
    public function index(
        Request $request,
        GetCustomerProfileAction $getCustomerProfile,
        ListCustomerBookingsAction $listCustomerBookings,
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

        $serviceId = $this->requestedServiceId($request);

        if ($serviceId === false) {
            return ApiResponse::error(
                'The given data was invalid.',
                'VALIDATION_ERROR',
                422,
                ['service_id' => ['The service id must be an integer.']],
            );
        }

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $bookings = $listCustomerBookings->handle(
                $profile,
                $status,
                $serviceId,
                min(max($request->integer('per_page', 15), 1), 100),
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve bookings.',
                'BOOKINGS_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Bookings retrieved successfully.',
            [
                'items' => BookingResource::collection($bookings->getCollection()),
                'pagination' => [
                    'current_page' => $bookings->currentPage(),
                    'per_page' => $bookings->perPage(),
                    'total' => $bookings->total(),
                    'last_page' => $bookings->lastPage(),
                ],
            ],
        );
    }

    public function store(
        StoreBookingRequest $request,
        GetCustomerProfileAction $getCustomerProfile,
        CreateBookingAction $createBooking,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return ApiResponse::error(
                    'Customer profile not found.',
                    'CUSTOMER_PROFILE_NOT_FOUND',
                    404,
                );
            }

            $booking = $createBooking->handle($profile, $request->validated());
        } catch (ModelNotFoundException) {
            return ApiResponse::error(
                'Customer address not found.',
                'CUSTOMER_ADDRESS_NOT_FOUND',
                404,
            );
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to create booking.',
                'BOOKING_CREATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Booking created successfully.',
            new BookingResource($booking),
            201,
        );
    }

    public function show(
        Request $request,
        int $booking,
        GetCustomerProfileAction $getCustomerProfile,
        GetCustomerBookingAction $getCustomerBooking,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $customerBooking = $getCustomerBooking->handle($profile, $booking);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve booking.',
                'BOOKING_FETCH_FAILED',
                500,
            );
        }

        if ($customerBooking === null) {
            return ApiResponse::error(
                'Booking not found.',
                'BOOKING_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Booking retrieved successfully.',
            new BookingResource($customerBooking),
        );
    }

    public function cancel(
        CancelBookingRequest $request,
        int $booking,
        GetCustomerProfileAction $getCustomerProfile,
        CancelBookingAction $cancelBooking,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $customerBooking = $cancelBooking->handle(
                $profile,
                $booking,
                $request->validated('cancellation_reason'),
            );
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'VALIDATION_ERROR', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to cancel booking.',
                'BOOKING_CANCELLATION_FAILED',
                500,
            );
        }

        if ($customerBooking === null) {
            return ApiResponse::error(
                'Booking not found.',
                'BOOKING_NOT_FOUND',
                404,
            );
        }

        return ApiResponse::success(
            'Booking cancelled successfully.',
            new BookingResource($customerBooking),
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

    private function requestedStatus(Request $request): BookingStatus|false|null
    {
        $status = $request->query('status');

        if ($status === null || $status === '') {
            return null;
        }

        return is_string($status) ? BookingStatus::tryFrom($status) ?? false : false;
    }

    private function requestedServiceId(Request $request): int|false|null
    {
        $serviceId = $request->query('service_id');

        if ($serviceId === null || $serviceId === '') {
            return null;
        }

        return filter_var($serviceId, FILTER_VALIDATE_INT) === false
            ? false
            : (int) $serviceId;
    }

}
