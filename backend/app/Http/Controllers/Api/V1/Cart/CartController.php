<?php

namespace App\Http\Controllers\Api\V1\Cart;

use App\Actions\Cart\AddToCartAction;
use App\Actions\Cart\ClearCartAction;
use App\Actions\Cart\GetCartAction;
use App\Actions\Cart\RemoveCartItemAction;
use App\Actions\Cart\UpdateCartItemAction;
use App\Actions\Customer\GetCustomerProfileAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Cart\AddToCartRequest;
use App\Http\Requests\Api\V1\Cart\UpdateCartItemRequest;
use App\Http\Resources\Api\V1\Cart\CartResource;
use App\Models\User;
use App\Support\ApiResponse;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CartController extends Controller
{
    public function show(
        Request $request,
        GetCustomerProfileAction $getCustomerProfile,
        GetCartAction $getCart,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $cart = $getCart->handle($profile);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to retrieve cart.',
                'CART_FETCH_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Cart retrieved successfully.',
            new CartResource($cart),
        );
    }

    public function storeItem(
        AddToCartRequest $request,
        GetCustomerProfileAction $getCustomerProfile,
        AddToCartAction $addToCart,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $cart = $addToCart->handle(
                $profile,
                (int) $request->validated('product_id'),
                (int) $request->validated('quantity'),
            );
        } catch (ModelNotFoundException) {
            return ApiResponse::error(
                'Product not found.',
                'PRODUCT_NOT_FOUND',
                404,
            );
        } catch (DomainException $exception) {
            return ApiResponse::error(
                $exception->getMessage(),
                'VALIDATION_ERROR',
                422,
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to add item to cart.',
                'CART_ADD_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Item added to cart successfully.',
            new CartResource($cart),
        );
    }

    public function updateItem(
        UpdateCartItemRequest $request,
        int $item,
        GetCustomerProfileAction $getCustomerProfile,
        UpdateCartItemAction $updateCartItem,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $cart = $updateCartItem->handle(
                $profile,
                $item,
                (int) $request->validated('quantity'),
            );
        } catch (ModelNotFoundException) {
            return ApiResponse::error(
                'Cart item not found.',
                'CART_ITEM_NOT_FOUND',
                404,
            );
        } catch (DomainException $exception) {
            return ApiResponse::error(
                $exception->getMessage(),
                'VALIDATION_ERROR',
                422,
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to update cart item.',
                'CART_UPDATE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Cart item updated successfully.',
            new CartResource($cart),
        );
    }

    public function destroyItem(
        Request $request,
        int $item,
        GetCustomerProfileAction $getCustomerProfile,
        RemoveCartItemAction $removeCartItem,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $cart = $removeCartItem->handle($profile, $item);
        } catch (ModelNotFoundException) {
            return ApiResponse::error(
                'Cart item not found.',
                'CART_ITEM_NOT_FOUND',
                404,
            );
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to remove cart item.',
                'CART_REMOVE_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Cart item removed successfully.',
            new CartResource($cart),
        );
    }

    public function destroy(
        Request $request,
        GetCustomerProfileAction $getCustomerProfile,
        ClearCartAction $clearCart,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $profile = $getCustomerProfile->handle($user);

            if ($profile === null) {
                return $this->profileNotFound();
            }

            $cart = $clearCart->handle($profile);
        } catch (Throwable $exception) {
            report($exception);

            return ApiResponse::error(
                'Failed to clear cart.',
                'CART_CLEAR_FAILED',
                500,
            );
        }

        return ApiResponse::success(
            'Cart cleared successfully.',
            new CartResource($cart),
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
}
