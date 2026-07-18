<?php

namespace App\Http\Controllers\Api\V1\Favorites;

use App\Contracts\Favorite\Services\FavoriteServiceInterface;
use App\Exceptions\Favorite\FavoriteServiceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Favorites\ListFavoritesRequest;
use App\Http\Requests\Api\V1\Favorites\StoreFavoriteRequest;
use App\Http\Resources\Api\V1\Favorites\FavoriteResource;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function __construct(private FavoriteServiceInterface $favorites) {}

    public function store(StoreFavoriteRequest $request): JsonResponse
    {
        $profile = $this->resolveActiveProfile($request, $error);

        if ($profile === null) {
            return $error;
        }

        try {
            $result = $this->favorites->add($profile, $request->integer('service_id'));
        } catch (FavoriteServiceNotFoundException) {
            return $this->serviceNotFound();
        }

        // Idempotent add (API Design §12.1): 201 on first add, 200 thereafter.
        return ApiResponse::success(
            $result['created'] ? 'Service added to favorites.' : 'Service is already in favorites.',
            new FavoriteResource($result['favorite']->load([
                'service.modes',
                'service.coverageCities',
                'service.media',
            ])),
            $result['created'] ? 201 : 200,
        );
    }

    public function destroy(Request $request, int $service): JsonResponse
    {
        $profile = $this->resolveActiveProfile($request, $error);

        if ($profile === null) {
            return $error;
        }

        try {
            $this->favorites->remove($profile, $service);
        } catch (FavoriteServiceNotFoundException) {
            return $this->serviceNotFound();
        }

        // Idempotent remove (API Design §12.2): 200 whether or not a
        // favorite existed; 404 only for unknown/inaccessible services.
        return ApiResponse::success('Service removed from favorites.');
    }

    public function index(ListFavoritesRequest $request): JsonResponse
    {
        $profile = $this->resolveActiveProfile($request, $error);

        if ($profile === null) {
            return $error;
        }

        $paginator = $this->favorites->listForCustomer($profile, $request->perPage());

        return ApiResponse::success(
            'Favorites retrieved successfully.',
            ['items' => FavoriteResource::collection($paginator->items())],
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    private function resolveActiveProfile(Request $request, ?JsonResponse &$error): ?CustomerProfile
    {
        $user = $request->user();
        $profile = $user instanceof User ? $user->customerProfile : null;

        if ($profile === null) {
            $error = ApiResponse::error(
                'Customer profile not found.',
                'CUSTOMER_PROFILE_NOT_FOUND',
                404,
            );

            return null;
        }

        if (! $profile->canUseCustomerServices()) {
            $error = ApiResponse::error(
                'Your account is not active.',
                'ACCOUNT_INACTIVE',
                403,
            );

            return null;
        }

        return $profile;
    }

    private function serviceNotFound(): JsonResponse
    {
        return ApiResponse::error('Service not found.', 'NOT_FOUND', 404);
    }
}
