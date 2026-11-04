<?php

namespace App\Providers;

use App\Application\Saga\ServiceOrderSagaOrchestrator;
use App\Application\ServiceOrder\Ports\CatalogClientInterface;
use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use App\Domain\Notification\Interfaces\NotificationRepositoryInterface;
use App\Domain\Saga\Interfaces\ServiceOrderSagaRepositoryInterface;
use App\Domain\ServiceOrder\Interfaces\ServiceOrderRepositoryInterface;
use App\Domain\Vehicle\Interfaces\VehicleRepositoryInterface;
use App\Infrastructure\Http\HttpExecutionCatalogClient;
use App\Infrastructure\Persistence\Eloquent\Repositories\CustomerRepositoryEloquent;
use App\Infrastructure\Persistence\Eloquent\Repositories\NotificationRepositoryEloquent;
use App\Infrastructure\Persistence\Eloquent\Repositories\ServiceOrderRepositoryEloquent;
use App\Infrastructure\Persistence\Eloquent\Repositories\ServiceOrderSagaRepositoryEloquent;
use App\Infrastructure\Persistence\Eloquent\Repositories\VehicleRepositoryEloquent;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VehicleRepositoryInterface::class, VehicleRepositoryEloquent::class);
        $this->app->bind(CustomerRepositoryInterface::class, CustomerRepositoryEloquent::class);
        $this->app->bind(NotificationRepositoryInterface::class, NotificationRepositoryEloquent::class);
        $this->app->bind(ServiceOrderRepositoryInterface::class, ServiceOrderRepositoryEloquent::class);
        $this->app->bind(ServiceOrderSagaRepositoryInterface::class, ServiceOrderSagaRepositoryEloquent::class);

        $this->app->bind(CatalogClientInterface::class, fn (Application $app) => new HttpExecutionCatalogClient(
            baseUrl: (string) config('services.execution.url'),
            timeoutSeconds: (int) config('services.execution.timeout', 5),
            bearerToken: $app->make('request')->bearerToken(),
        ));

        $this->app->when(ServiceOrderSagaOrchestrator::class)
            ->needs('$approvalTimeoutMinutes')
            ->give(fn () => (int) config('saga.approval_timeout_minutes'));
        $this->app->when(ServiceOrderSagaOrchestrator::class)
            ->needs('$paymentTimeoutMinutes')
            ->give(fn () => (int) config('saga.payment_timeout_minutes'));
    }
}
