<?php

use Illuminate\Support\Facades\Schedule;

// Compensa sagas paradas esperando o cliente além do prazo (ver config/saga.php).
// Roda no container "scheduler" do Deployment worker (php artisan schedule:work).
Schedule::command('saga:timeouts')->everyMinute()->withoutOverlapping();
