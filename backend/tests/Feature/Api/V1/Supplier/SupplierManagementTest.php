<?php

namespace Tests\Feature\Api\V1\Supplier;

use App\Enums\SupplierStatus;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_a_supplier(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/suppliers', [
                'name' => 'Horn Africa Supplies',
                'contact_person' => 'Amina Ali',
                'phone' => '+252612345678',
                'email' => 'orders@hornafrica.test',
                'address' => 'Mogadishu, Somalia',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Supplier created successfully.')
            ->assertJsonPath('data.name', 'Horn Africa Supplies')
            ->assertJsonPath('data.contact_person', 'Amina Ali')
            ->assertJsonPath('data.phone', '+252612345678')
            ->assertJsonPath('data.email', 'orders@hornafrica.test')
            ->assertJsonPath('data.address', 'Mogadishu, Somalia')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonMissingPath('data.purchase_orders')
            ->assertJsonMissingPath('data.goods_receipts');

        $this->assertDatabaseHas('suppliers', [
            'name' => 'Horn Africa Supplies',
            'status' => SupplierStatus::Active->value,
            'deleted_at' => null,
        ]);
    }

    public function test_authenticated_user_can_update_a_supplier(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create([
            'name' => 'Old Name',
            'status' => SupplierStatus::Active,
        ]);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->putJson('/api/v1/suppliers/'.$supplier->id, [
                'name' => 'Updated Supplies Co',
                'contact_person' => 'Hassan Omar',
                'phone' => '+252698765432',
                'email' => 'billing@updated.test',
                'address' => 'Hargeisa',
                'status' => 'inactive',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Supplier updated successfully.')
            ->assertJsonPath('data.name', 'Updated Supplies Co')
            ->assertJsonPath('data.contact_person', 'Hassan Omar')
            ->assertJsonPath('data.status', 'inactive');

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => 'Updated Supplies Co',
            'status' => SupplierStatus::Inactive->value,
        ]);
    }

    public function test_authenticated_user_can_soft_delete_a_supplier(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create([
            'name' => 'Delete Me Supplies',
        ]);

        $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->deleteJson('/api/v1/suppliers/'.$supplier->id)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Supplier deleted successfully.');

        $this->assertSoftDeleted('suppliers', [
            'id' => $supplier->id,
            'name' => 'Delete Me Supplies',
        ]);

        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'name' => 'Delete Me Supplies',
        ]);
    }

    public function test_authenticated_user_can_list_suppliers_newest_first(): void
    {
        $user = User::factory()->create();
        $older = Supplier::factory()->create([
            'name' => 'Older Supplier',
            'created_at' => now()->subDay(),
        ]);
        $newer = Supplier::factory()->create([
            'name' => 'Newer Supplier',
            'created_at' => now(),
        ]);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/suppliers');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Suppliers retrieved successfully.')
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.items.0.name', $newer->name)
            ->assertJsonPath('data.items.1.name', $older->name)
            ->assertJsonMissingPath('data.items.0.purchase_orders');
    }

    public function test_authenticated_user_can_view_supplier_detail(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create([
            'name' => 'Detail Supplier',
            'contact_person' => 'Nuur Abdi',
        ]);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/suppliers/'.$supplier->id);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'Supplier retrieved successfully.')
            ->assertJsonPath('data.id', $supplier->id)
            ->assertJsonPath('data.name', 'Detail Supplier')
            ->assertJsonPath('data.contact_person', 'Nuur Abdi')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonMissingPath('data.purchase_orders')
            ->assertJsonMissingPath('data.goods_receipts');
    }

    public function test_authenticated_user_can_search_suppliers_by_name_or_contact_person(): void
    {
        $user = User::factory()->create();
        Supplier::factory()->create([
            'name' => 'Blue Ocean Trading',
            'contact_person' => 'Sara Yusuf',
        ]);
        Supplier::factory()->create([
            'name' => 'Desert Logistics',
            'contact_person' => 'Omar Farah',
        ]);

        $token = $user->createToken('admin-panel')->plainTextToken;

        $byName = $this->withToken($token)->getJson('/api/v1/suppliers?search=Blue Ocean');
        $byContact = $this->withToken($token)->getJson('/api/v1/suppliers?search=Omar');

        $byName
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'Blue Ocean Trading');

        $byContact
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.contact_person', 'Omar Farah');
    }

    public function test_authenticated_user_can_filter_suppliers_by_status(): void
    {
        $user = User::factory()->create();
        Supplier::factory()->create(['name' => 'Active Supplier']);
        Supplier::factory()->inactive()->create(['name' => 'Inactive Supplier']);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->getJson('/api/v1/suppliers?status=inactive');

        $response
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.name', 'Inactive Supplier')
            ->assertJsonPath('data.items.0.status', 'inactive');
    }

    public function test_supplier_create_returns_validation_errors(): void
    {
        $user = User::factory()->create();
        Supplier::factory()->create(['name' => 'Existing Supplier']);

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/suppliers', [
                'name' => 'Existing Supplier',
                'email' => 'not-an-email',
                'status' => 'archived',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['errors' => ['name', 'email', 'status']]);
    }

    public function test_soft_deleted_supplier_name_can_be_reused(): void
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create(['name' => 'Reusable Name']);
        $supplier->delete();

        $response = $this
            ->withToken($user->createToken('admin-panel')->plainTextToken)
            ->postJson('/api/v1/suppliers', [
                'name' => 'Reusable Name',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Reusable Name')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseCount('suppliers', 2);
    }

    public function test_guest_cannot_access_supplier_endpoints(): void
    {
        $supplier = Supplier::factory()->create();

        $this->getJson('/api/v1/suppliers')
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->getJson('/api/v1/suppliers/'.$supplier->id)
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->postJson('/api/v1/suppliers', ['name' => 'Guest Supplier'])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->putJson('/api/v1/suppliers/'.$supplier->id, ['name' => 'Guest Update'])
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');

        $this->deleteJson('/api/v1/suppliers/'.$supplier->id)
            ->assertUnauthorized()
            ->assertJsonPath('error_code', 'UNAUTHENTICATED');
    }
}
