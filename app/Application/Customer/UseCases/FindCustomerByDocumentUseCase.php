<?php

namespace App\Application\Customer\UseCases;

use App\Domain\Customer\Entities\Customer;
use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use App\Domain\Customer\ValueObjects\Document;

/**
 * Usado pela Lambda de autenticação por CPF (endpoint interno): a Lambda
 * não acessa mais o banco do os-service diretamente.
 */
class FindCustomerByDocumentUseCase
{
    public function __construct(private CustomerRepositoryInterface $customers) {}

    public function execute(string $document): ?Customer
    {
        $normalized = (new Document($document))->getValue();

        return $this->customers->findByDocument($normalized);
    }
}
