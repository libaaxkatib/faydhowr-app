<?php

namespace App\Http\Controllers\Api\V1\Admin\Bookings;

use App\Actions\Booking\AdminCancelBookingAction;
use App\Actions\Booking\CloseBookingAction;
use App\Actions\Booking\CompleteBookingAction;
use App\Actions\Booking\GetAdminBookingAction;
use App\Actions\Booking\ListAdminBookingsAction;
use App\Actions\Booking\ScheduleBookingAction;
use App\Actions\Booking\StartBookingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Bookings\CancelBookingRequest;
use App\Http\Requests\Api\V1\Admin\Bookings\ListAdminBookingsRequest;
use App\Http\Requests\Api\V1\Admin\Bookings\ScheduleBookingRequest;
use App\Http\Resources\Api\V1\Admin\Bookings\AdminBookingResource;
use App\Http\Resources\Api\V1\Admin\Payments\AdminPaymentResource;
use App\Models\Admin;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class BookingController extends Controller
{
    public function index(
        ListAdminBookingsRequest $request,
        ListAdminBookingsAction $listAdminBookings,
    ): JsonResponse {
        $paginator = $listAdminBookings->handle($request->toFilters());

        return ApiResponse::success(
            'Bookings retrieved successfully.',
            ['items' => AdminBookingResource::collection($paginator->items())],
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
        int $booking,
        GetAdminBookingAction $getAdminBooking,
    ): JsonResponse {
        $detail = $getAdminBooking->handle($booking);

        if ($detail === null) {
            return $this->notFound();
        }

        return ApiResponse::success(
            'Booking retrieved successfully.',
            array_merge(
                (new AdminBookingResource($detail['booking']))->resolve(),
                [
                    'payments' => AdminPaymentResource::collection($detail['payments'])->resolve(),
                    'can_schedule' => $detail['can_schedule'],
                    'can_close' => $detail['can_close'],
                ],
            ),
        );
    }

    public function schedule(
        ScheduleBookingRequest $request,
        int $booking,
        ScheduleBookingAction $scheduleBooking,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $scheduled = $scheduleBooking->handle(
                $admin,
                $booking,
                $request->scheduledStartAt(),
                $request->scheduledEndAt(),
            );
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'BOOKING_STATE_INVALID', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Failed to schedule booking.', 'BOOKING_SCHEDULE_FAILED', 500);
        }

        return ApiResponse::success(
            'Booking scheduled successfully.',
            new AdminBookingResource($scheduled->load(['customerProfile', 'service', 'serviceMode'])),
        );
    }

    public function start(
        Request $request,
        int $booking,
        StartBookingAction $startBooking,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $started = $startBooking->handle($admin, $booking);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'BOOKING_STATE_INVALID', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Failed to start booking.', 'BOOKING_START_FAILED', 500);
        }

        return ApiResponse::success(
            'Booking started successfully.',
            new AdminBookingResource($started->load(['customerProfile', 'service', 'serviceMode'])),
        );
    }

    public function complete(
        Request $request,
        int $booking,
        CompleteBookingAction $completeBooking,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $completed = $completeBooking->handle($admin, $booking);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'BOOKING_STATE_INVALID', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Failed to complete booking.', 'BOOKING_COMPLETE_FAILED', 500);
        }

        return ApiResponse::success(
            'Booking completed successfully.',
            new AdminBookingResource($completed->load(['customerProfile', 'service', 'serviceMode'])),
        );
    }

    public function close(
        Request $request,
        int $booking,
        CloseBookingAction $closeBooking,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $closed = $closeBooking->handle($admin, $booking);
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'BOOKING_STATE_INVALID', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Failed to close booking.', 'BOOKING_CLOSE_FAILED', 500);
        }

        return ApiResponse::success(
            'Booking closed successfully.',
            new AdminBookingResource($closed->load(['customerProfile', 'service', 'serviceMode'])),
        );
    }

    public function cancel(
        CancelBookingRequest $request,
        int $booking,
        AdminCancelBookingAction $cancelBooking,
    ): JsonResponse {
        /** @var Admin $admin */
        $admin = $request->user();

        try {
            $cancelled = $cancelBooking->handle($admin, $booking, $request->cancellationReason());
        } catch (ModelNotFoundException) {
            return $this->notFound();
        } catch (DomainException $exception) {
            return ApiResponse::error($exception->getMessage(), 'BOOKING_STATE_INVALID', 422);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error('Failed to cancel booking.', 'BOOKING_CANCEL_FAILED', 500);
        }

        return ApiResponse::success(
            'Booking cancelled successfully.',
            new AdminBookingResource($cancelled->load(['customerProfile', 'service', 'serviceMode'])),
        );
    }

    private function notFound(): JsonResponse
    {
        return ApiResponse::error('Booking not found.', 'BOOKING_NOT_FOUND', 404);
    }
}
