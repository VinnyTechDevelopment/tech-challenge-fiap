<?php

namespace App\Providers;

use App\Application\Notification\Handlers\NotifyCustomerOnPaymentRequested;
use App\Application\Notification\Handlers\NotifyCustomerOnQuoteSent;
use App\Application\Notification\Handlers\NotifyCustomerOnServiceOrderFinalized;
use App\Application\Notification\Handlers\NotifyMechanicsOnServiceOrderCreated;
use App\Application\Notification\Handlers\SendServiceOrderStatusNotification;
use App\Application\Notification\Handlers\SendWelcomeNotification;
use App\Domain\Customer\Events\CustomerCreated;
use App\Domain\ServiceOrder\Events\ServiceOrderCreated;
use App\Domain\ServiceOrder\Events\ServiceOrderPaymentRequested;
use App\Domain\ServiceOrder\Events\ServiceOrderQuoteSent;
use App\Domain\ServiceOrder\Events\ServiceOrderStatusChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        Event::listen(
            CustomerCreated::class,
            SendWelcomeNotification::class
        );
        Event::listen(
            ServiceOrderStatusChanged::class,
            SendServiceOrderStatusNotification::class
        );

        Event::listen(
            ServiceOrderCreated::class,
            NotifyMechanicsOnServiceOrderCreated::class
        );

        Event::listen(
            ServiceOrderQuoteSent::class,
            NotifyCustomerOnQuoteSent::class
        );

        Event::listen(
            ServiceOrderPaymentRequested::class,
            NotifyCustomerOnPaymentRequested::class
        );

        Event::listen(
            ServiceOrderStatusChanged::class,
            NotifyCustomerOnServiceOrderFinalized::class
        );
    }
}
