<?php

namespace App\Infrastructure\Messaging;

use App\Application\Messaging\Message;
use App\Application\Messaging\MessagePublisherInterface;
use Illuminate\Support\Facades\DB;

/**
 * Transactional Outbox: a mensagem é gravada na mesma transação do banco
 * que alterou o estado (ex.: OS + passo da saga). O "outbox:relay" envia
 * depois para o SQS. Assim nunca existe estado salvo sem a mensagem
 * correspondente (nem mensagem enviada de um estado que sofreu rollback).
 */
class OutboxMessagePublisher implements MessagePublisherInterface
{
    public function __construct(private DirectMessagePublisher $direct) {}

    public function publish(string $queue, Message $message): void
    {
        $message = $this->direct->prepare($message);

        DB::table('outbox_messages')->insert([
            'id' => $message->id,
            'queue' => $queue,
            'type' => $message->type,
            'saga_id' => $message->sagaId,
            'body' => $message->toJson(),
            'attempts' => 0,
            'created_at' => now(),
        ]);
    }
}
