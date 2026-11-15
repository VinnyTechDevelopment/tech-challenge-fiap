<?php

declare(strict_types=1);

namespace App\Domain\ServiceOrder\Events;

use App\Domain\ServiceOrder\Entities\ServiceOrder;

/**
 * Orçamento gerado pelo billing-service: os links de aprovação/recusa vão
 * por e-mail para o cliente.
 */
class ServiceOrderQuoteSent
{
    public function __construct(
        public readonly ServiceOrder $serviceOrder,
        public readonly ?string $approvalUrl = null,
        public readonly ?string $rejectUrl = null,
    ) {}
}
