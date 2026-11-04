<?php

namespace Tests\Unit\Application\Customer\UseCases;

use App\Application\Customer\DTOs\CreateCustomerDTO;
use App\Application\Customer\UseCases\CreateCustomerUseCase;
use App\Domain\Customer\Entities\Customer;
use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class CreateCustomerUseCaseTest extends TestCase
{
    private CustomerRepositoryInterface&MockInterface $repository;

    private CreateCustomerUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = Mockery::mock(CustomerRepositoryInterface::class);
        $this->useCase = new CreateCustomerUseCase($this->repository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_creates_and_returns_customer(): void
    {
        $this->repository->shouldReceive('findByDocument')
            ->once()
            ->with('52998224725')
            ->andReturnNull();

        $this->repository->shouldReceive('save')
            ->once()
            ->with(Mockery::type(Customer::class));

        $dto = new CreateCustomerDTO('João', 'joao@example.com', '11999990000', '52998224725');

        $result = $this->useCase->execute($dto);

        $this->assertInstanceOf(Customer::class, $result);
        $this->assertSame('João', $result->name);
        $this->assertSame('joao@example.com', $result->email);
        $this->assertSame('52998224725', $result->document);
    }

    public function test_throws_exception_if_document_already_registered(): void
    {
        $existing = Customer::create('Outro', 'outro@example.com', '11000000000', '52998224725');

        $this->repository->shouldReceive('findByDocument')
            ->once()
            ->with('52998224725')
            ->andReturn($existing);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cliente já cadastrado');

        $dto = new CreateCustomerDTO('João', 'joao@example.com', '11999990000', '52998224725');
        $this->useCase->execute($dto);
    }

    public function test_throws_exception_on_invalid_document(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $dto = new CreateCustomerDTO('João', 'joao@example.com', '11999990000', '00000000000');
        $this->useCase->execute($dto);
    }
}
