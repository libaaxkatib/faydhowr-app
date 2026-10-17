<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeDocument;
use App\Models\Permission;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesDocumentDisk;
use Tests\TestCase;

/**
 * Authentication and permission must answer BEFORE a record is looked up. Laravel resolves route-bound models (an employee,
 * a document, a company, ...) in `SubstituteBindings`; if the project's own `admin` / `permission:*` / `super_admin` checks
 * ran after it, a signed-in caller without the permission would get 404 for an id that does not exist and 403 for one that
 * does - revealing which records exist. These tests walk the real route table, so a route added later is covered too.
 */
class AdminAuthorizationBeforeLookupTest extends TestCase
{
    use FakesDocumentDisk;
    use RefreshDatabase;

    private const NONEXISTENT_ID = '987654321';

    protected function setUp(): void
    {
        parent::setUp();

        // About 300 requests come from one caller; the rate limiter is not what is being tested here.
        foreach (['admin-operations', 'auth-login', 'api'] as $limiter) {
            RateLimiter::for($limiter, fn () => Limit::none());
        }
    }

    /** The test client keeps the first signed-in user across requests, so forget it before acting as someone else. */
    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** An administrator who holds no permission at all (the HR Employee role has no default grants). */
    private function nobodyToken(): string
    {
        return Admin::factory()->create(['role' => AdminRole::HrEmployee])->createToken('t')->plainTextToken;
    }

    private function tokenWithOnly(AdminPermission $permission): string
    {
        $admin = Admin::factory()->create(['role' => AdminRole::HrEmployee]);

        DB::table('admin_permissions')->insert([
            'admin_id' => $admin->id,
            'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $admin->createToken('t')->plainTextToken;
    }

    private function makeEmployee(string $name = 'Lookup Probe'): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => $name,
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => 'active',
            'application_date' => now()->toDateString(),
        ]);
    }

    private function makeDocument(Employee $employee, bool $withFile = true): EmployeeDocument
    {
        $path = 'employees/'.$employee->id.'/documents/lookupprobe'.fake()->unique()->numerify('####').'.pdf';

        if ($withFile) {
            Storage::disk('local')->put($path, 'X');
        }

        return EmployeeDocument::query()->create([
            'employee_id' => $employee->id,
            'admin_id' => Admin::factory()->create()->id,
            'file_name' => 'probe.pdf',
            'file_type' => 'pdf',
            'file_size' => 1,
            'file_path' => $path,
            'verification_status' => 'pending',
            'is_current' => true,
        ]);
    }

    /**
     * Every admin route that sits behind sign-in AND a permission / Super-Admin guard.
     *
     * @return array{routes: list<array{method: string, uri: string, bound: bool}>, skipped: list<string>}
     */
    private function guardedAdminRoutes(): array
    {
        $routes = [];
        $skipped = [];

        foreach (Route::getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! str_starts_with($route->uri(), 'api/v1/admin/')) {
                continue;
            }

            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $guarded = collect($middleware)->contains(fn (string $m): bool => str_starts_with($m, 'permission:') || $m === 'super_admin');

            if (! $guarded || ! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }

            $uri = $route->uri();
            $unresolvable = false;

            foreach ($route->parameterNames() as $parameter) {
                $constraint = $route->wheres[$parameter] ?? null;

                if ($constraint === null || $constraint === '[0-9]+') {
                    $value = self::NONEXISTENT_ID;
                } elseif (preg_match('/^[a-z_|-]+$/', $constraint) === 1) {
                    $value = explode('|', $constraint)[0];
                } else {
                    $unresolvable = true;

                    continue;
                }

                $uri = str_replace('{'.$parameter.'}', $value, $uri);
                $uri = str_replace('{'.$parameter.'?}', $value, $uri);
            }

            if ($unresolvable || str_contains($uri, '{')) {
                $skipped[] = implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();

                continue;
            }

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = ['method' => $method, 'uri' => '/'.$uri, 'bound' => $route->parameterNames() !== []];
            }
        }

        return ['routes' => $routes, 'skipped' => $skipped];
    }

    public function test_the_route_table_is_actually_being_walked(): void
    {
        ['routes' => $routes, 'skipped' => $skipped] = $this->guardedAdminRoutes();

        $this->assertGreaterThanOrEqual(250, count($routes), 'Expected the large majority of the guarded admin API to be covered.');
        $this->assertGreaterThanOrEqual(110, count(array_filter($routes, fn (array $r): bool => $r['bound'])), 'Routes with record ids must be covered.');
        $this->assertLessThanOrEqual(5, count($skipped), 'Routes that cannot be requested are listed here: '.implode('; ', $skipped));
    }

    public function test_an_unauthenticated_caller_gets_401_from_every_guarded_admin_route(): void
    {
        foreach ($this->guardedAdminRoutes()['routes'] as ['method' => $method, 'uri' => $uri]) {
            $status = $this->json($method, $uri)->status();

            $this->assertSame(401, $status, "{$method} {$uri} must answer 401 to an unauthenticated caller (got {$status}).");
        }
    }

    public function test_a_caller_without_the_permission_gets_403_from_every_guarded_route_even_for_ids_that_do_not_exist(): void
    {
        $token = $this->nobodyToken();
        $failures = [];

        foreach ($this->guardedAdminRoutes()['routes'] as ['method' => $method, 'uri' => $uri]) {
            $response = $this->asToken($token)->json($method, $uri);

            if ($response->status() !== 403) {
                $failures[] = "{$method} {$uri} -> {$response->status()}";
            }
        }

        $this->assertSame([], $failures, "These routes answered something other than 403 to a caller with no permission:\n".implode("\n", array_slice($failures, 0, 30)));
    }

    public function test_the_refusal_is_the_same_for_a_record_that_exists_and_one_that_does_not(): void
    {
        Storage::fake('local');
        $this->fakeLocalDisk();
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee);
        $other = $this->makeEmployee('Other Person');
        $foreign = $this->makeDocument($other);
        $base = '/api/v1/admin/hr/employees';
        $absent = self::NONEXISTENT_ID;

        $requests = [
            ['PATCH', "{$base}/{$employee->id}/documents/{$document->id}/verify", "{$base}/{$employee->id}/documents/{$absent}/verify", "{$base}/{$absent}/documents/{$document->id}/verify"],
            ['DELETE', "{$base}/{$employee->id}/documents/{$document->id}", "{$base}/{$employee->id}/documents/{$absent}", "{$base}/{$absent}/documents/{$document->id}"],
            ['POST', "{$base}/{$employee->id}/documents", "{$base}/{$absent}/documents", "{$base}/{$absent}/documents"],
            ['POST', "{$base}/{$employee->id}/profile-picture", "{$base}/{$absent}/profile-picture", "{$base}/{$absent}/profile-picture"],
            ['GET', "{$base}/{$employee->id}", "{$base}/{$absent}", "{$base}/{$absent}"],
            ['GET', "{$base}/{$employee->id}/documents/{$document->id}/download", "{$base}/{$employee->id}/documents/{$absent}/download", "{$base}/{$absent}/documents/{$document->id}/download"],
        ];

        foreach ([$this->nobodyToken(), $this->tokenWithOnly(AdminPermission::HrView)] as $index => $token) {
            foreach ($requests as [$method, $existing, $missingDocument, $missingEmployee]) {
                // The view-only caller IS allowed on the two GET routes, so only compare what it must be refused.
                if ($index === 1 && $method === 'GET') {
                    continue;
                }

                $answers = collect([$existing, $missingDocument, $missingEmployee])->map(function (string $uri) use ($method, $token): array {
                    $response = $this->asToken($token)->json($method, $uri);

                    return [$response->status(), $response->json('error_code'), $response->json('message')];
                });

                $this->assertSame([[403, 'FORBIDDEN', 'You do not have permission to perform this action.']], $answers->unique()->values()->all(), "{$method} {$existing} must refuse identically whether or not the record exists.");
            }
        }

        // And the other employee's document, requested through the wrong employee, is refused the same way.
        $this->asToken($this->nobodyToken())->deleteJson("{$base}/{$employee->id}/documents/{$foreign->id}")->assertStatus(403);
    }

    public function test_an_authorized_caller_still_gets_the_real_answers(): void
    {
        Storage::fake('local');
        $this->fakeLocalDisk();
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee);
        $other = $this->makeEmployee('Other Person');
        $foreign = $this->makeDocument($other);
        $base = '/api/v1/admin/hr/employees';
        $absent = self::NONEXISTENT_ID;
        $manage = $this->tokenWithOnly(AdminPermission::HrManage);
        $view = $this->tokenWithOnly(AdminPermission::HrView);

        // hr.view: record found / not found
        $this->asToken($view)->getJson("{$base}/{$employee->id}")->assertOk();
        $this->asToken($view)->getJson("{$base}/{$absent}")->assertStatus(404);
        $this->asToken($view)->get("{$base}/{$employee->id}/documents/{$document->id}/download")->assertOk();
        $this->asToken($view)->getJson("{$base}/{$employee->id}/documents/{$foreign->id}/download")->assertStatus(404)->assertJsonPath('error_code', 'EMPLOYEE_DOCUMENT_NOT_FOUND');

        // hr.manage: the ownership check (a document must belong to the employee in the URL) still applies
        $this->asToken($manage)->patchJson("{$base}/{$employee->id}/documents/{$foreign->id}/verify")->assertStatus(404)->assertJsonPath('error_code', 'EMPLOYEE_DOCUMENT_NOT_FOUND');
        $this->asToken($manage)->patchJson("{$base}/{$employee->id}/documents/{$absent}/verify")->assertStatus(404);
        $this->asToken($manage)->patchJson("{$base}/{$absent}/documents/{$document->id}/verify")->assertStatus(404);
        $this->asToken($manage)->patchJson("{$base}/{$employee->id}/documents/{$document->id}/verify")->assertOk()->assertJsonPath('data.verification_status', 'verified');

        // upload / profile picture: existing employee works, nonexistent is 404
        $this->asToken($manage)->post("{$base}/{$employee->id}/documents", ['file' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf')])->assertStatus(201);
        $this->asToken($manage)->post("{$base}/{$absent}/documents", ['file' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf')])->assertStatus(404);
        $this->asToken($manage)->post("{$base}/{$employee->id}/profile-picture", ['file' => UploadedFile::fake()->image('p.jpg')])->assertOk();
        $this->asToken($manage)->post("{$base}/{$absent}/profile-picture", ['file' => UploadedFile::fake()->image('p.jpg')])->assertStatus(404);

        // delete: wrong owner / nonexistent are 404, the real one is removed
        $this->asToken($manage)->deleteJson("{$base}/{$employee->id}/documents/{$foreign->id}")->assertStatus(404);
        $this->asToken($manage)->deleteJson("{$base}/{$employee->id}/documents/{$absent}")->assertStatus(404);
        $this->asToken($manage)->deleteJson("{$base}/{$employee->id}/documents/{$document->id}")->assertOk();
        $this->assertDatabaseMissing('employee_documents', ['id' => $document->id]);
        $this->assertDatabaseHas('employee_documents', ['id' => $foreign->id]);
    }

    public function test_the_super_admin_still_reaches_every_kind_of_route(): void
    {
        Storage::fake('local');
        $this->fakeLocalDisk();
        $employee = $this->makeEmployee();
        $token = Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;

        $this->asToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}")->assertOk();
        $this->asToken($token)->getJson('/api/v1/admin/hr/employees/'.self::NONEXISTENT_ID)->assertStatus(404);
    }
}
