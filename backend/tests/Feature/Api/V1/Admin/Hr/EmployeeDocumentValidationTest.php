<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 7 security audit (P7-11): the HR document/profile-picture upload
 * flow itself had no test asserting rejection of a disallowed file type or
 * an oversized file (only the generic /api/v1/uploads endpoint had this
 * coverage). Validation rules themselves are unchanged - this file only
 * proves the existing StoreEmployeeDocumentRequest / StoreEmployeeProfile
 * PictureRequest rules actually reject what they claim to.
 */
class EmployeeDocumentValidationTest extends TestCase
{
    use RefreshDatabase;

    private function actingToken(): string
    {
        return Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;
    }

    private function makeEmployee(): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => 'active',
            'application_date' => now()->toDateString(),
        ]);
    }

    public function test_document_upload_rejects_a_disallowed_file_extension(): void
    {
        Storage::fake('local');
        $token = $this->actingToken();
        $employee = $this->makeEmployee();

        $this->withToken($token)
            ->post("/api/v1/admin/hr/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(0, $employee->documents()->count());
    }

    public function test_document_upload_rejects_a_file_over_the_10mb_cap(): void
    {
        Storage::fake('local');
        $token = $this->actingToken();
        $employee = $this->makeEmployee();

        $this->withToken($token)
            ->post("/api/v1/admin/hr/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);

        $this->assertSame(0, $employee->documents()->count());
    }

    public function test_profile_picture_upload_rejects_a_disallowed_file_type(): void
    {
        Storage::fake('local');
        $token = $this->actingToken();
        $employee = $this->makeEmployee();

        $this->withToken($token)
            ->post("/api/v1/admin/hr/employees/{$employee->id}/profile-picture", [
                'file' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    public function test_profile_picture_upload_rejects_a_file_over_the_5mb_cap(): void
    {
        Storage::fake('local');
        $token = $this->actingToken();
        $employee = $this->makeEmployee();

        $this->withToken($token)
            ->post("/api/v1/admin/hr/employees/{$employee->id}/profile-picture", [
                'file' => UploadedFile::fake()->create('big.jpg', 6 * 1024, 'image/jpeg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }
}
