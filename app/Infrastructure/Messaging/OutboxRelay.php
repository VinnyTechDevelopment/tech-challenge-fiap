<?php

namespace App\Infrastructure\Messaging;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OutboxRelay
{
    public function __construct(private QueueTransportInterface $transport) {}

    /**
     * Envia as mensagens pendentes em ordem de criação. Retorna quantas foram enviadas.
     */
    public function relay(int $batchSize = 50): int
    {
        $pending = DB::table('outbox_messages')
            ->whereNull('sent_at')
            ->orderBy('sequence')
            ->limit($batchSize)
            ->get();

        $sent = 0;

        foreach ($pending as $row) {
            try {
                $this->transport->send($row->queue, $row->body, ['type' => $row->type, 'saga_id' => $row->saga_id]);

                DB::table('outbox_messages')->where('id', $row->id)->update([
                    'sent_at' => now(),
                    'attempts' => $row->attempts + 1,
                    'last_error' => null,
                ]);

                $sent++;
            } catch (\Throwable $e) {
                DB::table('outbox_messages')->where('id', $row->id)->update([
                    'attempts' => $row->attempts + 1,
                    'last_error' => mb_substr($e->getMessage(), 0, 1000),
                ]);

                Log::error('outbox.relay_failed', ['id' => $row->id, 'type' => $row->type, 'error' => $e->getMessage()]);

                // Preserva a ordem: não envia mensagens posteriores da mesma leva.
                break;
            }
        }

        return $sent;
    }
}
