<?php

use App\Application\Messaging\MessageTypes;
use App\Application\Saga\Handlers\SagaReplyHandler;
use App\Infrastructure\Messaging\DatabaseProcessedMessageStore;
use App\Infrastructure\Messaging\OutboxMessagePublisher;

return [
    // sqs (EKS / LocalStack) | memory (testes)
    'driver' => env('MESSAGING_DRIVER', 'memory'),

    'queue_prefix' => env('MESSAGING_QUEUE_PREFIX', 'local'),

    'source' => 'os-service',

    'inbound_queue' => MessageTypes::QUEUE_OS_SAGA_REPLIES,

    'sqs' => [
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'endpoint' => env('AWS_ENDPOINT_URL'),
    ],

    // Transactional outbox: OS, saga, histórico e comandos na mesma transação.
    'publisher' => OutboxMessagePublisher::class,

    'processed_store' => DatabaseProcessedMessageStore::class,

    // Todas as respostas dos participantes vão para o orquestrador.
    'handlers' => array_fill_keys([
        MessageTypes::BILLING_QUOTE_CREATED,
        MessageTypes::BILLING_QUOTE_CREATION_FAILED,
        MessageTypes::BILLING_QUOTE_APPROVED,
        MessageTypes::BILLING_QUOTE_REJECTED,
        MessageTypes::BILLING_QUOTE_CANCELLED,
        MessageTypes::BILLING_PAYMENT_CONFIRMED,
        MessageTypes::BILLING_PAYMENT_FAILED,
        MessageTypes::BILLING_PAYMENT_REFUNDED,
        MessageTypes::BILLING_PAYMENT_REFUND_FAILED,
        MessageTypes::EXECUTION_QUEUED,
        MessageTypes::EXECUTION_RESERVATION_FAILED,
        MessageTypes::EXECUTION_STATUS_CHANGED,
        MessageTypes::EXECUTION_FINISHED,
        MessageTypes::EXECUTION_CANCELLED,
    ], SagaReplyHandler::class),
];
