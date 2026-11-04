<?php

namespace App\Domain\Customer\Events;

use App\Domain\Customer\Entities\Customer;

class CustomerCreated
{
    public function __construct(public Customer $customer) {}
}
