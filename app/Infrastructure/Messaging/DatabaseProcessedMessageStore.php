<?php

namespace App\Infrastructure\Messaging;

use App\Application\Messaging\Message;
use App\Application\Messaging\ProcessedMessageStoreInterface;
use Illuminate\Support\Facades\DB;

/**
 * Inbox idempotente: registra o message_id na MESMA transação do handler.
 * Se o handler falhar, nada é gravado (nem o registro, nem o outbox) e a
 * mensagem pode ser reprocessada com segurança.
 */
class DatabaseProcessedMessageStore implements ProcessedMessageStoreInterface
{
    public function runOnce(Message $message, callable $handler): bool
    {
        return DB::transaction(function () use ($message, $handler): bool {
            $inserted = DB::table('processed_messages')->insertOrIgnore([
                'message_id' => $message->id,
                'type' => $message->type,
                'saga_id' => $message->sagaId,
                'processed_at' => now(),
            ]);

            if ($inserted === 0) {
                return false;
            }

            $handler();

            return true;
        });
    }
}
