<?php

namespace Tests\Feature\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Customer\Entities\Customer;
use App\Infrastructure\Persistence\Eloquent\Repositories\CustomerRepositoryEloquent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerRepositoryEloquentTest extends TestCase
{
    use RefreshDatabase;

    private CustomerRepositoryEloquent $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new CustomerRepositoryEloquent;
    }

    private function authenticateUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    private function makeCustomer(
        string $name = 'Maria',
        string $email = 'maria@example.com',
        string $phone = '11999990000',
        string $document = '52998224725'
    ): Customer {
        return new Customer(
            Str::uuid()->toString(),
            $name,
            $email,
            $phone,
            $document
        );
    }

    public function test_save_persists_customer_with_audit_user_ids(): void
    {
        $user = $this->authenticateUser();
        $customer = $this->makeCustomer();

        $this->repository->save($customer);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Maria',
            'email' => 'maria@example.com',
            'document' => '52998224725',
            'created_user_id' => $user->id,
            'updated_user_id' => $user->id,
        ]);
    }

    public function test_find_by_id_returns_customer_when_found(): void
    {
        $this->authenticateUser();
        $customer = $this->makeCustomer();
        $this->repository->save($customer);

        $found = $this->repository->findById($customer->id);

        $this->assertNotNull($found);
        $this->assertSame($customer->id, $found->id);
        $this->assertSame($customer->email, $found->email);
    }

    public function test_find_by_id_returns_null_when_not_found(): void
    {
        $found = $this->repository->findById('non-existent-id');

        $this->assertNull($found);
    }

    public function test_find_by_document_returns_customer_when_found(): void
    {
        $this->authenticateUser();
        $customer = $this->makeCustomer(document: '11144477735');
        $this->repository->save($customer);

        $found = $this->repository->findByDocument('11144477735');

        $this->assertNotNull($found);
        $this->assertSame('11144477735', $found->document);
        $this->assertSame($customer->id, $found->id);
    }

    public function test_find_by_email_returns_customer_when_found(): void
    {
        $this->authenticateUser();
        $customer = $this->makeCustomer(email: 'ana@example.com', document: '98765432100');
        $this->repository->save($customer);

        $found = $this->repository->findByEmail('ana@example.com');

        $this->assertNotNull($found);
        $this->assertSame('ana@example.com', $found->email);
        $this->assertSame($customer->id, $found->id);
    }

    public function test_find_all_returns_all_customers_as_array(): void
    {
        $this->authenticateUser();
        $this->repository->save($this->makeCustomer(name: 'A', email: 'a@example.com', document: '12345678909'));
        $this->repository->save($this->makeCustomer(name: 'B', email: 'b@example.com', document: '98765432100'));

        $all = $this->repository->findAll();

        $this->assertCount(2, $all);
        $this->assertIsArray($all[0]);
        $this->assertArrayHasKey('id', $all[0]);
        $this->assertArrayHasKey('email', $all[0]);
    }

    public function test_paginate_returns_expected_page_size(): void
    {
        $this->authenticateUser();

        for ($i = 1; $i <= 5; $i++) {
            $this->repository->save($this->makeCustomer(
                name: "Name {$i}",
                email: "user{$i}@example.com",
                document: sprintf('%011d', $i)
            ));
        }

        $pageTwo = $this->repository->paginate(2, 2);

        $this->assertCount(2, $pageTwo);
        $this->assertIsArray($pageTwo[0]);
        $this->assertArrayHasKey('name', $pageTwo[0]);
    }

    public function test_update_persists_new_customer_data(): void
    {
        $firstUser = $this->authenticateUser();
        $customer = $this->makeCustomer();
        $this->repository->save($customer);

        $secondUser = User::factory()->create();
        $this->actingAs($secondUser);

        $customer->updateData('Maria Atualizada', 'maria.new@example.com', '11888887777', '11144477735');
        $this->repository->update($customer);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Maria Atualizada',
            'email' => 'maria.new@example.com',
            'phone' => '11888887777',
            'document' => '11144477735',
            'created_user_id' => $firstUser->id,
            'updated_user_id' => $secondUser->id,
        ]);
    }

    public function test_delete_removes_customer_from_database(): void
    {
        $this->authenticateUser();
        $customer = $this->makeCustomer();
        $this->repository->save($customer);

        $this->repository->delete($customer->id);

        $this->assertDatabaseMissing('customers', [
            'id' => $customer->id,
        ]);
    }
}
