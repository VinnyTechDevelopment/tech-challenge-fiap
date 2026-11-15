<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\MessagingServiceProvider;
use App\Providers\RepositoryServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    MessagingServiceProvider::class,
    RepositoryServiceProvider::class,
];
