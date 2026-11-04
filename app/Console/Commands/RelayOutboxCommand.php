<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\OutboxRelay;
use Illuminate\Console\Command;

class RelayOutboxCommand extends Command
{
    protected $signature = 'outbox:relay
        {--once : Envia um único lote e sai}
        {--sleep=1 : Segundos de espera quando não há mensagens pendentes}';

    protected $description = 'Envia para o SQS as mensagens gravadas no outbox transacional';

    private bool $shouldStop = false;

    public function handle(OutboxRelay $relay): int
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn () => $this->shouldStop = true);
        }

        do {
            $sent = $relay->relay();

            if ($sent > 0) {
                $this->line("{$sent} mensagem(ns) enviada(s).");
            } elseif (! $this->option('once')) {
                sleep(max(0, (int) $this->option('sleep')));
            }
        } while (! $this->option('once') && ! $this->shouldStop);

        return self::SUCCESS;
    }
}
