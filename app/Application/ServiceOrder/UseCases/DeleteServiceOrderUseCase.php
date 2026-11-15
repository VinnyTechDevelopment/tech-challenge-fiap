<?php

namespace App\Application\ServiceOrder\UseCases;

use App\Application\ServiceOrder\DTOs\DeleteServiceOrderDTO;
use App\Domain\ServiceOrder\Interfaces\ServiceOrderRepositoryInterface;

class DeleteServiceOrderUseCase
{
    public function __construct(
        private ServiceOrderRepositoryInterface $repository
    ) {}

    public function execute(DeleteServiceOrderDTO $dto): void
    {
        $serviceOrder = $this->repository->findById($dto->id);

        if (! $serviceOrder) {
            throw new \DomainException('Ordem de servico nao encontrada');
        }

        // Uma OS com saga em andamento tem estado em outros serviços
        // (orçamento, pagamento, reserva de peças): use o cancelamento.
        if (! $serviceOrder->isFinal()) {
            throw new \DomainException('Apenas ordens de servico entregues ou canceladas podem ser removidas. Use o cancelamento.');
        }

        $this->repository->delete($dto->id);
    }
}
