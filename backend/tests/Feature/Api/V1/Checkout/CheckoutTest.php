<?php

namespace Tests\Feature\Api\V1\Checkout;

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerAddress;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_generate_a_checkout_summary(): void
    {
        [$user, $profile] = $this->customer();
        $address = CustomerAddress::factory()->create([
            'customer_profile_id' => $profile->id,
            'label' => 'Home',
            'city' => 'Mogadishu',
            'is_active' => true,
        ]);
        $product = Product::factory()->create([
            'selling_price' => 12.50,
            'current_stock' => 10,
            'status' => ProductStatus::Active,
        ]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Checkout summary generated successfully.')
            ->assertJsonPath('data.items.0.sku', $product->sku)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', '12.50')
            ->assertJsonPath('data.items.0.line_total', '25.00')
            ->assertJsonPath('data.totals.total_items', 1)
            ->assertJsonPath('data.totals.total_quantity', 2)
            ->assertJsonPath('data.totals.subtotal', '25.00')
            ->assertJsonPath('data.address.label', 'Home')
            ->assertJsonPath('data.address.city', 'Mogadishu')
            ->assertJsonMissingPath('data.items.0.cost_price')
            ->assertJsonMissingPath('data.address.id');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'current_stock' => 10,
        ]);
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
    }

    public function test_checkout_rejects_empty_cart(): void
    {
        [$user, $profile] = $this->customer();
        $address = CustomerAddress::factory()->create([
            'customer_profile_id' => $profile->id,
        ]);
        Cart::factory()->create(['customer_profile_id' => $profile->id]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'CART_EMPTY');
    }

    public function test_checkout_rejects_inactive_product(): void
    {
        [$user, $profile] = $this->customer();
        $address = CustomerAddress::factory()->create([
            'customer_profile_id' => $profile->id,
        ]);
        $product = Product::factory()->inactive()->create([
            'current_stock' => 5,
        ]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'One or more cart products are inactive.');
    }

    public function test_checkout_rejects_out_of_stock_product(): void
    {
        [$user, $profile] = $this->customer();
        $address = CustomerAddress::factory()->create([
            'customer_profile_id' => $profile->id,
        ]);
        $product = Product::factory()->create([
            'current_stock' => 1,
            'status' => ProductStatus::Active,
        ]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'One or more cart products have insufficient stock.');
    }

    public function test_checkout_rejects_invalid_address(): void
    {
        [$user, $profile] = $this->customer();
        $product = Product::factory()->create(['current_stock' => 5]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/checkout', [
                'address_id' => 999999,
            ])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'ADDRESS_NOT_FOUND');
    }

    public function test_checkout_rejects_another_customers_address(): void
    {
        [$user, $profile] = $this->customer();
        $otherAddress = CustomerAddress::factory()->create([
            'customer_profile_id' => CustomerProfile::factory()->create()->id,
        ]);
        $product = Product::factory()->create(['current_stock' => 5]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/checkout', [
                'address_id' => $otherAddress->id,
            ])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'ADDRESS_NOT_FOUND');
    }

    public function test_checkout_requires_customer_profile(): void
    {
        $user = User::factory()->create();

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/checkout', [
                'address_id' => 1,
            ])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_guest_cannot_checkout(): void
    {
        $this->postJson('/api/v1/checkout', [
            'address_id' => 1,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }

    /**
     * @return array{0: User, 1: CustomerProfile}
     */
    private function customer(): array
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->create(['user_id' => $user->id]);

        return [$user, $profile];
    }
}
