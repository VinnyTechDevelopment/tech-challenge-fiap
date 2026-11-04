<?php

namespace Tests\Unit\Application\Customer\UseCases;

use App\Application\Customer\DTOs\ShowCustomerDTO;
use App\Application\Customer\UseCases\ShowCustomerUseCase;
use App\Domain\Customer\Entities\Customer;
use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class ShowCustomerUseCaseTest extends TestCase
{
    private CustomerRepositoryInterface&MockInterface $repository;

    private ShowCustomerUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(CustomerRepositoryInterface::class);
        $this->useCase = new ShowCustomerUseCase($this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_customer_by_id(): void
    {
        $customer = Customer::create('João', 'joao@example.com', '11999990000', '52998224725');

        $this->repository->shouldReceive('findById')
            ->once()
            ->with($customer->id)
            ->andReturn($customer);

        $dto = new ShowCustomerDTO($customer->id);
        $result = $this->useCase->execute($dto);

        $this->assertSame($customer, $result);
    }

    public function test_throws_exception_when_customer_not_found(): void
    {
        $this->repository->shouldReceive('findById')
            ->once()
            ->with('non-existent-id')
            ->andReturnNull();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cliente não encontrado');

        $dto = new ShowCustomerDTO('non-existent-id');
        $this->useCase->execute($dto);
    }
}
