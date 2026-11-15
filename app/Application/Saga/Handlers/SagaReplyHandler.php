<?php

namespace App\Application\Saga\Handlers;

use App\Application\Messaging\Message;
use App\Application\Messaging\MessageHandlerInterface;
use App\Application\Saga\ServiceOrderSagaOrchestrator;

/**
 * Todas as respostas de billing/execution (fila os-saga-replies) vão para o
 * orquestrador.
 */
class SagaReplyHandler implements MessageHandlerInterface
{
    public function __construct(private ServiceOrderSagaOrchestrator $orchestrator) {}

    public function handle(Message $message): void
    {
        $this->orchestrator->handle($message);
    }
}
