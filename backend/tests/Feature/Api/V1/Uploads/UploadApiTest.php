<?php

namespace Tests\Feature\Api\V1\Uploads;

use App\Models\CustomerProfile;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @return array{0: string, 1: CustomerProfile}
     */
    private function createCustomer(): array
    {
        $user = User::factory()->create();
        $profile = CustomerProfile::factory()->for($user)->create();
        $token = $user->createToken('customer-mobile')->plainTextToken;

        return [$token, $profile];
    }

    /**
     * A genuinely decodable 1x1 PNG so server-side image validation passes.
     */
    private function fakeImage(string $name = 'photo.png'): UploadedFile
    {
        $pngBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        );

        return UploadedFile::fake()->createWithContent($name, $pngBytes);
    }

    /**
     * A minimal file carrying the %PDF- signature.
     */
    private function fakePdf(string $name = 'document.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, '%PDF-1.4 fake-but-signed');
    }

    public function test_guest_cannot_access_upload_endpoints(): void
    {
        $uuid = '11111111-1111-1111-1111-111111111111';

        $this->postJson('/api/v1/uploads')->assertUnauthorized();
        $this->getJson('/api/v1/uploads')->assertUnauthorized();
        $this->getJson("/api/v1/uploads/{$uuid}")->assertUnauthorized();
        $this->deleteJson("/api/v1/uploads/{$uuid}")->assertUnauthorized();
    }

    public function test_customer_can_stage_multiple_files(): void
    {
        [$token, $profile] = $this->createCustomer();

        $response = $this
            ->withToken($token)
            ->postJson('/api/v1/uploads', [
                'files' => [
                    $this->fakeImage('kitchen.png'),
                    $this->fakePdf('scope.pdf'),
                    UploadedFile::fake()->create('walkthrough.mp4', 500, 'video/mp4'),
                ],
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.0.file_name', 'kitchen.png')
            ->assertJsonPath('data.items.0.media_type', 'image')
            ->assertJsonPath('data.items.1.media_type', 'document')
            ->assertJsonPath('data.items.2.media_type', 'video')
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        '*' => ['uuid', 'file_name', 'mime_type', 'media_type', 'file_size_bytes', 'created_at'],
                    ],
                ],
            ]);

        $item = $response->json('data.items.0');
        $this->assertArrayNotHasKey('id', $item);
        $this->assertArrayNotHasKey('path', $item);
        $this->assertArrayNotHasKey('disk', $item);

        $this->assertSame(3, Upload::query()->count());

        Upload::query()->each(function (Upload $upload) use ($profile): void {
            $this->assertSame($profile->id, (int) $upload->customer_profile_id);
            $this->assertTrue(Storage::disk('local')->exists($upload->path));
            $this->assertNull($upload->attached_at);
            $this->assertNotNull($upload->expires_at);
            $this->assertTrue($upload->expires_at->isFuture());
        });
    }

    public function test_upload_rejects_files_outside_the_allow_list(): void
    {
        [$token] = $this->createCustomer();

        $this
            ->withToken($token)
            ->postJson('/api/v1/uploads', [
                'files' => [UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload')],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['files.0']);

        $this
            ->withToken($token)
            ->postJson('/api/v1/uploads', [
                'files' => [UploadedFile::fake()->create('animation.gif', 10, 'image/gif')],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['files.0']);

        $this->assertSame(0, Upload::query()->count());
    }

    public function test_upload_rejects_images_that_cannot_be_decoded(): void
    {
        [$token] = $this->createCustomer();

        $notReallyAnImage = UploadedFile::fake()->createWithContent('photo.jpg', 'this is not image data');

        $this
            ->withToken($token)
            ->postJson('/api/v1/uploads', ['files' => [$notReallyAnImage]])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'INVALID_IMAGE_FILE');

        $this->assertSame(0, Upload::query()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_upload_rejects_pdfs_without_the_pdf_signature(): void
    {
        [$token] = $this->createCustomer();

        $notReallyAPdf = UploadedFile::fake()->createWithContent('contract.pdf', 'MZ fake executable payload');

        $this
            ->withToken($token)
            ->postJson('/api/v1/uploads', ['files' => [$notReallyAPdf]])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'INVALID_PDF_FILE');

        $this->assertSame(0, Upload::query()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_valid_batch_fails_atomically_when_one_file_has_invalid_content(): void
    {
        [$token] = $this->createCustomer();

        $this
            ->withToken($token)
            ->postJson('/api/v1/uploads', [
                'files' => [
                    $this->fakeImage('good.png'),
                    UploadedFile::fake()->createWithContent('bad.pdf', 'no signature here'),
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'INVALID_PDF_FILE');

        $this->assertSame(0, Upload::query()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_upload_enforces_per_type_size_caps(): void
    {
        [$token] = $this->createCustomer();

        $oversizedImage = UploadedFile::fake()->create('big.jpg', 11 * 1024, 'image/jpeg');
        $oversizedPdf = UploadedFile::fake()->create('big.pdf', 21 * 1024, 'application/pdf');

        $this
            ->withToken($token)
            ->postJson('/api/v1/uploads', ['files' => [$oversizedImage]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0']);

        $this
            ->withToken($token)
            ->postJson('/api/v1/uploads', ['files' => [$oversizedPdf]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files.0']);
    }

    public function test_upload_rejects_more_than_ten_files_per_request(): void
    {
        [$token] = $this->createCustomer();

        $files = [];
        for ($i = 0; $i < 11; $i++) {
            $files[] = $this->fakeImage("photo-{$i}.png");
        }

        $this
            ->withToken($token)
            ->postJson('/api/v1/uploads', ['files' => $files])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['files']);
    }

    public function test_upload_enforces_staged_storage_quota(): void
    {
        [$token, $profile] = $this->createCustomer();

        Upload::factory()->for($profile)->create([
            'file_size_bytes' => 500 * 1024 * 1024 - 10,
        ]);

        $this
            ->withToken($token)
            ->postJson('/api/v1/uploads', ['files' => [$this->fakeImage()]])
            ->assertConflict()
            ->assertJsonPath('error_code', 'UPLOAD_STORAGE_LIMIT_EXCEEDED');

        $this->assertSame(1, Upload::query()->count());
    }

    public function test_attached_and_expired_uploads_do_not_count_toward_quota(): void
    {
        [$token, $profile] = $this->createCustomer();

        Upload::factory()->for($profile)->attached()->create([
            'file_size_bytes' => 400 * 1024 * 1024,
        ]);
        Upload::factory()->for($profile)->expired()->create([
            'file_size_bytes' => 400 * 1024 * 1024,
        ]);

        $this
            ->withToken($token)
            ->postJson('/api/v1/uploads', ['files' => [$this->fakeImage()]])
            ->assertCreated();
    }

    public function test_list_returns_only_own_unattached_non_expired_uploads(): void
    {
        [$token, $profile] = $this->createCustomer();
        [, $otherProfile] = $this->createCustomer();

        $staged = Upload::factory()->for($profile)->create();
        Upload::factory()->for($profile)->attached()->create();
        Upload::factory()->for($profile)->expired()->create();
        Upload::factory()->for($otherProfile)->create();

        $response = $this->withToken($token)->getJson('/api/v1/uploads');

        $response
            ->assertOk()
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.uuid', $staged->uuid);
    }

    public function test_list_pagination_is_validated_and_capped(): void
    {
        [$token] = $this->createCustomer();

        $this
            ->withToken($token)
            ->getJson('/api/v1/uploads?per_page=150')
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR');

        $this
            ->withToken($token)
            ->getJson('/api/v1/uploads?per_page=100')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);
    }

    public function test_owner_can_stream_their_upload(): void
    {
        [$token, $profile] = $this->createCustomer();

        Storage::disk('local')->put('uploads/'.$profile->id.'/doc.pdf', 'pdf-bytes');
        $upload = Upload::factory()->for($profile)->document()->create([
            'path' => 'uploads/'.$profile->id.'/doc.pdf',
            'original_name' => 'quotation-scope.pdf',
        ]);

        $response = $this->withToken($token)->get("/api/v1/uploads/{$upload->uuid}");

        $response->assertOk();
        $this->assertStringContainsString(
            'quotation-scope.pdf',
            (string) $response->headers->get('content-disposition'),
        );
        $this->assertSame('pdf-bytes', $response->streamedContent());
    }

    public function test_read_returns_404_for_unknown_foreign_or_expired_uploads(): void
    {
        [$token, $profile] = $this->createCustomer();
        [, $otherProfile] = $this->createCustomer();

        $this
            ->withToken($token)
            ->getJson('/api/v1/uploads/11111111-1111-1111-1111-111111111111')
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $foreign = Upload::factory()->for($otherProfile)->create();
        $this
            ->withToken($token)
            ->getJson("/api/v1/uploads/{$foreign->uuid}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $expired = Upload::factory()->for($profile)->expired()->create();
        $this
            ->withToken($token)
            ->getJson("/api/v1/uploads/{$expired->uuid}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    public function test_owner_can_delete_an_unattached_upload(): void
    {
        [$token, $profile] = $this->createCustomer();

        Storage::disk('local')->put('uploads/'.$profile->id.'/photo.jpg', 'image-bytes');
        $upload = Upload::factory()->for($profile)->create([
            'path' => 'uploads/'.$profile->id.'/photo.jpg',
        ]);

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/uploads/{$upload->uuid}")
            ->assertOk()
            ->assertJsonPath('message', 'Upload deleted successfully.');

        $this->assertDatabaseMissing('uploads', ['id' => $upload->id]);
        Storage::disk('local')->assertMissing('uploads/'.$profile->id.'/photo.jpg');
    }

    public function test_deleting_an_attached_upload_returns_conflict(): void
    {
        [$token, $profile] = $this->createCustomer();

        $upload = Upload::factory()->for($profile)->attached()->create();

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/uploads/{$upload->uuid}")
            ->assertConflict()
            ->assertJsonPath('error_code', 'UPLOAD_ATTACHED');

        $this->assertDatabaseHas('uploads', ['id' => $upload->id]);
    }

    public function test_deleting_a_foreign_upload_returns_404(): void
    {
        [$token] = $this->createCustomer();
        [, $otherProfile] = $this->createCustomer();

        $foreign = Upload::factory()->for($otherProfile)->create();

        $this
            ->withToken($token)
            ->deleteJson("/api/v1/uploads/{$foreign->uuid}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'NOT_FOUND');

        $this->assertDatabaseHas('uploads', ['id' => $foreign->id]);
    }

    public function test_customer_without_profile_receives_not_found(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('customer-mobile')->plainTextToken;

        $this
            ->withToken($token)
            ->postJson('/api/v1/uploads', ['files' => [$this->fakeImage()]])
            ->assertNotFound()
            ->assertJsonPath('error_code', 'CUSTOMER_PROFILE_NOT_FOUND');
    }

    public function test_upload_staging_is_rate_limited_per_customer(): void
    {
        [$token] = $this->createCustomer();

        for ($i = 0; $i < 20; $i++) {
            $this
                ->withToken($token)
                ->postJson('/api/v1/uploads', ['files' => [$this->fakeImage("photo-{$i}.png")]])
                ->assertCreated();
        }

        $this
            ->withToken($token)
            ->postJson('/api/v1/uploads', ['files' => [$this->fakeImage('one-too-many.png')]])
            ->assertTooManyRequests();
    }
}
