<?php

namespace Tests\Feature\Api\V1\Product;

use App\Enums\ProductStatus;
use App\Models\Admin;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_list_active_products_newest_first(): void
    {
        $older = Product::factory()->create([
            'name' => 'Older Product',
            'created_at' => now()->subDay(),
        ]);
        $newer = Product::factory()->create([
            'name' => 'Newer Product',
            'created_at' => now(),
        ]);
        Product::factory()->inactive()->create([
            'name' => 'Inactive Product',
        ]);

        $response = $this->getJson('/api/v1/products');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Products retrieved successfully.')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.name', $newer->name)
            ->assertJsonPath('data.items.1.name', $older->name)
            ->assertJsonMissingPath('data.items.0.cost_price')
            ->assertJsonMissingPath('data.items.0.id');
    }

    public function test_guest_can_filter_products_by_category_and_featured(): void
    {
        $category = ProductCategory::factory()->create();
        $otherCategory = ProductCategory::factory()->create();

        Product::factory()->featured()->create([
            'category_id' => $category->id,
            'name' => 'Featured Match',
        ]);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Not Featured',
            'is_featured' => false,
        ]);
        Product::factory()->featured()->create([
            'category_id' => $otherCategory->id,
            'name' => 'Other Category',
        ]);

        $response = $this->getJson(
            '/api/v1/products?category_id='.$category->id.'&featured=1',
        );

        $response
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'Featured Match')
            ->assertJsonPath('data.items.0.is_featured', true)
            ->assertJsonPath('data.items.0.category.slug', $category->slug);
    }

    public function test_guest_can_search_products_by_sku_or_name(): void
    {
        Product::factory()->create([
            'sku' => 'CLN-100001',
            'name' => 'Floor Cleaner',
        ]);
        Product::factory()->create([
            'sku' => 'PPE-200002',
            'name' => 'Safety Gloves',
        ]);

        $bySku = $this->getJson('/api/v1/products?search=CLN-100');
        $byName = $this->getJson('/api/v1/products?search=Gloves');

        $bySku
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.sku', 'CLN-100001');

        $byName
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'Safety Gloves');
    }

    public function test_guest_status_filter_is_ignored_and_only_active_products_are_returned(): void
    {
        Product::factory()->create(['name' => 'Active Product']);
        Product::factory()->inactive()->create(['name' => 'Hidden Product']);

        $response = $this->getJson('/api/v1/products?status=inactive');

        $response
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'Active Product');
    }

    public function test_authenticated_admin_can_filter_by_status(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        Product::factory()->create(['name' => 'Active Product']);
        Product::factory()->inactive()->create(['name' => 'Inactive Product']);

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/products?status=inactive');

        $response
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'Inactive Product')
            ->assertJsonPath('data.items.0.status', 'inactive');
    }

    public function test_guest_can_view_active_product_detail_without_cost_price(): void
    {
        $product = Product::factory()->create([
            'cost_price' => 12.50,
            'selling_price' => 25.00,
        ]);
        ProductImage::factory()->primary()->create([
            'product_id' => $product->id,
            'image_path' => 'products/primary.jpg',
        ]);
        ProductImage::factory()->create([
            'product_id' => $product->id,
            'image_path' => 'products/secondary.jpg',
            'sort_order' => 1,
            'is_primary' => false,
        ]);

        $product->load('images');
        $primaryImage = $product->images->firstWhere('is_primary', true);
        $additionalImage = $product->images->firstWhere('is_primary', false);

        $response = $this->getJson('/api/v1/products/'.$product->id);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sku', $product->sku)
            ->assertJsonPath('data.name', $product->name)
            ->assertJsonPath('data.slug', $product->slug)
            ->assertJsonPath('data.selling_price', '25.00')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.primary_image.image_url', $primaryImage->url())
            ->assertJsonPath('data.additional_images.0.image_url', $additionalImage->url())
            ->assertJsonPath('data.category.name', $product->category->name)
            ->assertJsonMissingPath('data.cost_price')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.created_at')
            ->assertJsonMissingPath('data.primary_image.image_path');
    }

    public function test_guest_cannot_view_inactive_product_detail(): void
    {
        $product = Product::factory()->inactive()->create();

        $this->getJson('/api/v1/products/'.$product->id)
            ->assertNotFound()
            ->assertJsonPath('error_code', 'PRODUCT_NOT_FOUND');
    }

    public function test_authenticated_user_can_create_a_product(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $category = ProductCategory::factory()->create();

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/products', [
                'category_id' => $category->id,
                'sku' => 'CLN-000245',
                'name' => 'Disinfectant Spray',
                'slug' => 'disinfectant-spray',
                'description' => 'Hospital-grade cleaner',
                'selling_price' => 15.5,
                'cost_price' => 8.25,
                'currency' => 'USD',
                'current_stock' => 40,
                'low_stock_threshold' => 5,
                'is_featured' => true,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Product created successfully.')
            ->assertJsonPath('data.sku', 'CLN-000245')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.selling_price', '15.50')
            ->assertJsonMissingPath('data.cost_price');

        $this->assertDatabaseHas('products', [
            'sku' => 'CLN-000245',
            'slug' => 'disinfectant-spray',
            'status' => ProductStatus::Active->value,
            'cost_price' => 8.25,
            'selling_price' => 15.5,
        ]);
    }

    public function test_product_creation_validation_rejects_invalid_payload(): void
    {
        $admin = Admin::factory()->superAdmin()->create();

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/products', [
                'category_id' => 999,
                'sku' => '',
                'name' => '',
                'slug' => '',
                'selling_price' => -1,
                'cost_price' => -2,
                'currency' => 'usd',
                'current_stock' => -1,
                'low_stock_threshold' => -5,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors([
                'category_id',
                'sku',
                'name',
                'slug',
                'selling_price',
                'cost_price',
                'currency',
                'current_stock',
                'low_stock_threshold',
            ]);
    }

    public function test_product_creation_rejects_duplicate_sku_and_slug(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $category = ProductCategory::factory()->create();
        Product::factory()->create([
            'sku' => 'DUP-SKU',
            'slug' => 'dup-slug',
        ]);

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/products', [
                'category_id' => $category->id,
                'sku' => 'DUP-SKU',
                'name' => 'Duplicate',
                'slug' => 'dup-slug',
                'selling_price' => 10,
                'cost_price' => 5,
                'currency' => 'USD',
                'current_stock' => 1,
                'low_stock_threshold' => 0,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['sku', 'slug']);
    }

    public function test_authenticated_user_can_update_a_product(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $product = Product::factory()->create([
            'name' => 'Old Name',
            'selling_price' => 10,
            'cost_price' => 4,
            'is_featured' => false,
            'status' => ProductStatus::Active,
        ]);
        $newCategory = ProductCategory::factory()->create();

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->putJson('/api/v1/products/'.$product->id, [
                'category_id' => $newCategory->id,
                'name' => 'Updated Name',
                'selling_price' => 18.75,
                'cost_price' => 9.10,
                'current_stock' => 12,
                'low_stock_threshold' => 3,
                'is_featured' => true,
                'status' => 'inactive',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Name')
            ->assertJsonPath('data.selling_price', '18.75')
            ->assertJsonPath('data.is_featured', true)
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.category.slug', $newCategory->slug)
            ->assertJsonMissingPath('data.cost_price');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated Name',
            'cost_price' => 9.10,
            'status' => ProductStatus::Inactive->value,
        ]);
    }

    public function test_authenticated_user_can_soft_delete_a_product(): void
    {
        $admin = Admin::factory()->superAdmin()->create();
        $product = Product::factory()->create();

        $response = $this
            ->withToken($admin->createToken('admin-panel')->plainTextToken)
            ->deleteJson('/api/v1/products/'.$product->id);

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Product deleted successfully.');

        $this->assertSoftDeleted('products', [
            'id' => $product->id,
        ]);

        $this->getJson('/api/v1/products/'.$product->id)
            ->assertNotFound()
            ->assertJsonPath('error_code', 'PRODUCT_NOT_FOUND');
    }

    public function test_guest_cannot_create_update_or_delete_products(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/v1/products', [])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->putJson('/api/v1/products/'.$product->id, [
            'name' => 'Hacked',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->deleteJson('/api/v1/products/'.$product->id)
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => $product->name,
            'deleted_at' => null,
        ]);
    }
}
