<?php

namespace App\Application\Customer\DTOs;

class UpdateCustomerDTO
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $email,
        public ?string $phone,
        public ?string $document
    ) {}
}
