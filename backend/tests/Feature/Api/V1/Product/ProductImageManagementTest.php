<?php

namespace Tests\Feature\Api\V1\Product;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductImageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string) config('products.images.disk'));
    }

    public function test_authenticated_user_can_upload_a_product_image(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $file = UploadedFile::fake()->image('cleaner.jpg', 600, 600);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->post('/api/v1/products/'.$product->id.'/images', [
                'image' => $file,
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Product image uploaded successfully.')
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.sort_order', 0)
            ->assertJsonStructure([
                'data' => ['id', 'image_url', 'is_primary', 'sort_order'],
            ])
            ->assertJsonMissingPath('data.image_path');

        $image = ProductImage::query()->where('product_id', $product->id)->first();

        self::assertNotNull($image);
        self::assertTrue($image->is_primary);
        Storage::disk((string) config('products.images.disk'))->assertExists($image->image_path);
    }

    public function test_first_uploaded_image_is_primary_and_later_images_are_not(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $token = $user->createToken('admin-panel')->plainTextToken;

        $first = $this
            ->withToken($token)
            ->post('/api/v1/products/'.$product->id.'/images', [
                'image' => UploadedFile::fake()->image('first.png'),
            ]);

        $second = $this
            ->withToken($token)
            ->post('/api/v1/products/'.$product->id.'/images', [
                'image' => UploadedFile::fake()->image('second.png'),
            ]);

        $first->assertCreated()->assertJsonPath('data.is_primary', true);
        $second->assertCreated()->assertJsonPath('data.is_primary', false);

        $this->assertDatabaseCount('product_images', 2);
        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'is_primary' => true,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'is_primary' => false,
            'sort_order' => 1,
        ]);
    }

    public function test_upload_validation_rejects_invalid_files(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $missing = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/products/'.$product->id.'/images', []);

        $invalidType = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->post('/api/v1/products/'.$product->id.'/images', [
                'image' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
            ]);

        $tooLarge = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->post('/api/v1/products/'.$product->id.'/images', [
                'image' => UploadedFile::fake()->image('huge.jpg')->size(
                    ((int) config('products.images.max_kilobytes')) + 1,
                ),
            ]);

        $missing
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['image']);

        $invalidType
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['image']);

        $tooLarge
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['image']);
    }

    public function test_authenticated_user_can_change_primary_image(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $primary = ProductImage::factory()->primary()->create([
            'product_id' => $product->id,
            'sort_order' => 0,
            'image_path' => 'products/'.$product->id.'/a.jpg',
        ]);
        $secondary = ProductImage::factory()->create([
            'product_id' => $product->id,
            'sort_order' => 1,
            'is_primary' => false,
            'image_path' => 'products/'.$product->id.'/b.jpg',
        ]);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->patchJson('/api/v1/products/'.$product->id.'/images/'.$secondary->id.'/primary');

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $secondary->id)
            ->assertJsonPath('data.is_primary', true);

        $this->assertDatabaseHas('product_images', [
            'id' => $secondary->id,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('product_images', [
            'id' => $primary->id,
            'is_primary' => false,
        ]);
        self::assertSame(1, ProductImage::query()->where('product_id', $product->id)->where('is_primary', true)->count());
    }

    public function test_authenticated_user_can_reorder_images(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $first = ProductImage::factory()->primary()->create([
            'product_id' => $product->id,
            'sort_order' => 0,
            'image_path' => 'products/'.$product->id.'/a.jpg',
        ]);
        $second = ProductImage::factory()->create([
            'product_id' => $product->id,
            'sort_order' => 1,
            'is_primary' => false,
            'image_path' => 'products/'.$product->id.'/b.jpg',
        ]);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->patchJson('/api/v1/products/'.$product->id.'/images/reorder', [
                'images' => [
                    ['id' => $second->id, 'sort_order' => 0],
                    ['id' => $first->id, 'sort_order' => 1],
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.sort_order', 0)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.1.sort_order', 1);

        $this->assertDatabaseHas('product_images', [
            'id' => $second->id,
            'sort_order' => 0,
        ]);
        $this->assertDatabaseHas('product_images', [
            'id' => $first->id,
            'sort_order' => 1,
        ]);
    }

    public function test_authenticated_user_can_delete_a_non_primary_image(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $primary = ProductImage::factory()->primary()->create([
            'product_id' => $product->id,
            'sort_order' => 0,
            'image_path' => 'products/'.$product->id.'/primary.jpg',
        ]);
        $secondary = ProductImage::factory()->create([
            'product_id' => $product->id,
            'sort_order' => 1,
            'is_primary' => false,
            'image_path' => 'products/'.$product->id.'/secondary.jpg',
        ]);

        Storage::disk((string) config('products.images.disk'))->put($secondary->image_path, 'fake');

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->deleteJson('/api/v1/products/'.$product->id.'/images/'.$secondary->id);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Product image deleted successfully.');

        $this->assertDatabaseMissing('product_images', ['id' => $secondary->id]);
        $this->assertDatabaseHas('product_images', [
            'id' => $primary->id,
            'is_primary' => true,
        ]);
        Storage::disk((string) config('products.images.disk'))->assertMissing($secondary->image_path);
    }

    public function test_deleting_primary_image_promotes_next_image(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $primary = ProductImage::factory()->primary()->create([
            'product_id' => $product->id,
            'sort_order' => 0,
            'image_path' => 'products/'.$product->id.'/primary.jpg',
        ]);
        $secondary = ProductImage::factory()->create([
            'product_id' => $product->id,
            'sort_order' => 1,
            'is_primary' => false,
            'image_path' => 'products/'.$product->id.'/secondary.jpg',
        ]);

        Storage::disk((string) config('products.images.disk'))->put($primary->image_path, 'fake');

        $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->deleteJson('/api/v1/products/'.$product->id.'/images/'.$primary->id)
            ->assertOk();

        $this->assertDatabaseMissing('product_images', ['id' => $primary->id]);
        $this->assertDatabaseHas('product_images', [
            'id' => $secondary->id,
            'is_primary' => true,
        ]);
        self::assertSame(1, ProductImage::query()->where('product_id', $product->id)->where('is_primary', true)->count());
    }

    public function test_deleting_last_image_leaves_product_without_primary(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();
        $image = ProductImage::factory()->primary()->create([
            'product_id' => $product->id,
            'sort_order' => 0,
            'image_path' => 'products/'.$product->id.'/only.jpg',
        ]);

        Storage::disk((string) config('products.images.disk'))->put($image->image_path, 'fake');

        $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->deleteJson('/api/v1/products/'.$product->id.'/images/'.$image->id)
            ->assertOk();

        $this->assertDatabaseCount('product_images', 0);
        self::assertSame(0, ProductImage::query()->where('product_id', $product->id)->count());
    }

    public function test_guest_cannot_manage_product_images(): void
    {
        $product = Product::factory()->create();
        $image = ProductImage::factory()->primary()->create([
            'product_id' => $product->id,
            'sort_order' => 0,
            'image_path' => 'products/'.$product->id.'/a.jpg',
        ]);

        $this->withHeaders(['Accept' => 'application/json'])
            ->post('/api/v1/products/'.$product->id.'/images', [
                'image' => UploadedFile::fake()->image('guest.jpg'),
            ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->patchJson('/api/v1/products/'.$product->id.'/images/'.$image->id.'/primary')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->patchJson('/api/v1/products/'.$product->id.'/images/reorder', [
            'images' => [
                ['id' => $image->id, 'sort_order' => 0],
            ],
        ])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->deleteJson('/api/v1/products/'.$product->id.'/images/'.$image->id)
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->assertDatabaseHas('product_images', [
            'id' => $image->id,
            'is_primary' => true,
        ]);
    }
}
