<?php

namespace Tests\Feature\Api\V1\Cart;

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CustomerProfile;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShoppingCartTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_customer_gets_an_empty_cart(): void
    {
        [$user] = $this->customer();

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/cart');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Cart retrieved successfully.')
            ->assertJsonPath('data.total_items', 0)
            ->assertJsonPath('data.total_quantity', 0)
            ->assertJsonPath('data.subtotal', '0.00')
            ->assertJsonPath('data.items', []);

        $this->assertDatabaseCount('carts', 1);
    }

    public function test_customer_can_add_a_product_to_the_cart(): void
    {
        [$user] = $this->customer();
        $product = Product::factory()->create([
            'selling_price' => 10.00,
            'current_stock' => 20,
            'status' => ProductStatus::Active,
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 2,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Item added to cart successfully.')
            ->assertJsonPath('data.total_items', 1)
            ->assertJsonPath('data.total_quantity', 2)
            ->assertJsonPath('data.subtotal', '20.00')
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.items.0.unit_price', '10.00')
            ->assertJsonPath('data.items.0.line_total', '20.00')
            ->assertJsonPath('data.items.0.product.sku', $product->sku)
            ->assertJsonMissingPath('data.items.0.product.cost_price')
            ->assertJsonMissingPath('data.items.0.product.id');

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
    }

    public function test_adding_an_existing_product_increases_quantity_without_duplicates(): void
    {
        [$user, $profile] = $this->customer();
        $product = Product::factory()->create([
            'selling_price' => 5.00,
            'current_stock' => 10,
        ]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 3,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.total_items', 1)
            ->assertJsonPath('data.total_quantity', 5)
            ->assertJsonPath('data.subtotal', '25.00');

        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 5,
        ]);
    }

    public function test_customer_can_update_cart_item_quantity(): void
    {
        [$user, $profile] = $this->customer();
        $product = Product::factory()->create([
            'selling_price' => 8.50,
            'current_stock' => 15,
        ]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->patchJson('/api/v1/cart/items/'.$item->id, [
                'quantity' => 4,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.total_quantity', 4)
            ->assertJsonPath('data.subtotal', '34.00')
            ->assertJsonPath('data.items.0.quantity', 4);

        $this->assertDatabaseHas('cart_items', [
            'id' => $item->id,
            'quantity' => 4,
        ]);
    }

    public function test_customer_can_remove_a_cart_item(): void
    {
        [$user, $profile] = $this->customer();
        $product = Product::factory()->create(['current_stock' => 10]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->deleteJson('/api/v1/cart/items/'.$item->id);

        $response
            ->assertOk()
            ->assertJsonPath('data.total_items', 0)
            ->assertJsonPath('data.items', []);

        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
    }

    public function test_customer_can_clear_cart_while_keeping_cart_record(): void
    {
        [$user, $profile] = $this->customer();
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => Product::factory()->create(['current_stock' => 5])->id,
            'quantity' => 1,
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => Product::factory()->create(['current_stock' => 5])->id,
            'quantity' => 2,
        ]);

        $response = $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->deleteJson('/api/v1/cart');

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Cart cleared successfully.')
            ->assertJsonPath('data.total_items', 0)
            ->assertJsonPath('data.subtotal', '0.00');

        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'customer_profile_id' => $profile->id,
        ]);
    }

    public function test_add_to_cart_rejects_quantity_above_stock(): void
    {
        [$user] = $this->customer();
        $product = Product::factory()->create([
            'current_stock' => 2,
            'selling_price' => 10,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 3,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonPath('message', 'The requested quantity exceeds available stock.');
    }

    public function test_update_rejects_quantity_above_stock(): void
    {
        [$user, $profile] = $this->customer();
        $product = Product::factory()->create(['current_stock' => 3]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->patchJson('/api/v1/cart/items/'.$item->id, [
                'quantity' => 5,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The requested quantity exceeds available stock.');
    }

    public function test_inactive_product_cannot_be_added_to_cart(): void
    {
        [$user] = $this->customer();
        $product = Product::factory()->inactive()->create([
            'current_stock' => 10,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Only active products can be added to the cart.');
    }

    public function test_out_of_stock_product_cannot_be_added_to_cart(): void
    {
        [$user] = $this->customer();
        $product = Product::factory()->create([
            'current_stock' => 0,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->postJson('/api/v1/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'The selected product is out of stock.');
    }

    public function test_cart_totals_use_current_selling_price(): void
    {
        [$user, $profile] = $this->customer();
        $first = Product::factory()->create([
            'selling_price' => 12.25,
            'current_stock' => 10,
        ]);
        $second = Product::factory()->create([
            'selling_price' => 3.50,
            'current_stock' => 10,
        ]);
        $cart = Cart::factory()->create(['customer_profile_id' => $profile->id]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $first->id,
            'quantity' => 2,
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $second->id,
            'quantity' => 3,
        ]);

        $this
            ->withToken($user->createToken('customer-mobile')->plainTextToken)
            ->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('data.total_items', 2)
            ->assertJsonPath('data.total_quantity', 5)
            ->assertJsonPath('data.subtotal', '35.00');
    }

    public function test_guest_cannot_access_cart_endpoints(): void
    {
        $this->getJson('/api/v1/cart')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->postJson('/api/v1/cart/items', [
            'product_id' => 1,
            'quantity' => 1,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->patchJson('/api/v1/cart/items/1', [
            'quantity' => 2,
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->deleteJson('/api/v1/cart/items/1')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->deleteJson('/api/v1/cart')
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
