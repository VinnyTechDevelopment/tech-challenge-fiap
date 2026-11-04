<?php

namespace App\Console\Commands;

use App\Application\Saga\ServiceOrderSagaOrchestrator;
use Illuminate\Console\Command;

class ExpireSagasCommand extends Command
{
    protected $signature = 'saga:timeouts';

    protected $description = 'Compensa sagas cujo prazo de aprovacao/pagamento expirou';

    public function handle(ServiceOrderSagaOrchestrator $orchestrator): int
    {
        $expired = $orchestrator->expireTimedOut(now()->toIso8601String());
        $this->info("{$expired} saga(s) expirada(s) compensada(s).");

        return self::SUCCESS;
    }
}
