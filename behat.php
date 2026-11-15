<?php

use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Tests\Behat\SagaOrdemServicoContext;

// BDD do fluxo completo da OS (Fase 4): features/*.feature em português.
// Roda com o mesmo banco de testes do PHPUnit: vendor/bin/behat
return (new Config)
    ->withProfile((new Profile('default'))
        ->withSuite((new Suite('saga'))
            ->withPaths('%paths.base%/features')
            ->addContext(SagaOrdemServicoContext::class, ['behat'])));
