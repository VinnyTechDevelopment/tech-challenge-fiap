<?php

namespace Tests\Unit\Application\Customer\UseCases;

use App\Application\Customer\DTOs\ListCustomerDTO;
use App\Application\Customer\UseCases\ListCustomerUseCase;
use App\Domain\Customer\Entities\Customer;
use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class ListCustomerUseCaseTest extends TestCase
{
    private CustomerRepositoryInterface&MockInterface $repository;

    private ListCustomerUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(CustomerRepositoryInterface::class);
        $this->useCase = new ListCustomerUseCase($this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_all_customers_when_no_page_given(): void
    {
        $customers = [
            Customer::create('A', 'a@example.com', '11111111111', '52998224725'),
        ];

        $this->repository->shouldReceive('findAll')
            ->once()
            ->andReturn($customers);

        $dto = new ListCustomerDTO(null, null);
        $result = $this->useCase->execute($dto);

        $this->assertSame($customers, $result);
    }

    public function test_paginates_when_page_is_given(): void
    {
        $customers = [
            Customer::create('B', 'b@example.com', '22222222222', '52998224725'),
        ];

        $this->repository->shouldReceive('paginate')
            ->once()
            ->with(1, 10)
            ->andReturn($customers);

        $dto = new ListCustomerDTO(1, null);
        $result = $this->useCase->execute($dto);

        $this->assertSame($customers, $result);
    }

    public function test_uses_provided_per_page(): void
    {
        $this->repository->shouldReceive('paginate')
            ->once()
            ->with(2, 25)
            ->andReturn([]);

        $dto = new ListCustomerDTO(2, 25);
        $result = $this->useCase->execute($dto);

        $this->assertSame([], $result);
    }

    public function test_defaults_per_page_to_10_when_invalid(): void
    {
        $this->repository->shouldReceive('paginate')
            ->once()
            ->with(1, 10)
            ->andReturn([]);

        $dto = new ListCustomerDTO(1, -5);
        $result = $this->useCase->execute($dto);

        $this->assertSame([], $result);
    }
}
