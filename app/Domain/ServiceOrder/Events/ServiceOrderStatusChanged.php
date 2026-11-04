<?php

namespace App\Domain\ServiceOrder\Events;

use App\Domain\ServiceOrder\Entities\ServiceOrder;

class ServiceOrderStatusChanged
{
    public function __construct(
        public readonly ServiceOrder $serviceOrder,
        public readonly string $oldStatus
    ) {}
}
