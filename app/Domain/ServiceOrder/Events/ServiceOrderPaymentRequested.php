<?php

declare(strict_types=1);

namespace App\Domain\ServiceOrder\Events;

use App\Domain\ServiceOrder\Entities\ServiceOrder;

/**
 * Orçamento aprovado: o cliente recebe o link de pagamento (Mercado Pago).
 */
class ServiceOrderPaymentRequested
{
    public function __construct(
        public readonly ServiceOrder $serviceOrder,
        public readonly ?string $paymentUrl,
    ) {}
}
