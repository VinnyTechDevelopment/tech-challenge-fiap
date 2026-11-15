<?php

namespace Tests\Unit\Application\Customer\UseCases;

use App\Application\Customer\DTOs\UpdateCustomerDTO;
use App\Application\Customer\UseCases\UpdateCustomerUseCase;
use App\Domain\Customer\Entities\Customer;
use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class UpdateCustomerUseCaseTest extends TestCase
{
    private CustomerRepositoryInterface&MockInterface $repository;

    private UpdateCustomerUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(CustomerRepositoryInterface::class);
        $this->useCase = new UpdateCustomerUseCase($this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_updates_and_returns_customer(): void
    {
        $customer = Customer::create('Antigo', 'antigo@example.com', '11000000000', '52998224725');

        $this->repository->shouldReceive('findById')
            ->once()
            ->with($customer->id)
            ->andReturn($customer);

        $this->repository->shouldReceive('update')
            ->once()
            ->with(Mockery::type(Customer::class));

        $dto = new UpdateCustomerDTO($customer->id, 'Novo Nome', null, null, null);
        $result = $this->useCase->execute($dto);

        $this->assertSame($customer, $result);
        $this->assertSame('Novo Nome', $result->name);
        $this->assertSame('antigo@example.com', $result->email);
    }

    public function test_throws_exception_when_customer_not_found(): void
    {
        $this->repository->shouldReceive('findById')
            ->once()
            ->with('non-existent-id')
            ->andReturnNull();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cliente não encontrado');

        $dto = new UpdateCustomerDTO('non-existent-id', 'Nome', null, null, null);
        $this->useCase->execute($dto);
    }
}
