<?php

namespace App\Application\Notification\Handlers;

use App\Domain\Customer\Interfaces\CustomerRepositoryInterface;
use App\Domain\Notification\Entities\Notification as EntityNotification;
use App\Domain\Notification\Interfaces\NotificationRepositoryInterface;
use App\Domain\Notification\Interfaces\NotificationServiceInterface;
use App\Domain\Notification\ValueObjects\NotificationStatus;
use App\Domain\Notification\ValueObjects\NotificationType;
use App\Domain\ServiceOrder\Events\ServiceOrderQuoteSent;
use Illuminate\Support\Str;

class NotifyCustomerOnQuoteSent
{
    public function __construct(
        private NotificationServiceInterface $notificationService,
        private NotificationRepositoryInterface $notificationRepository,
        private CustomerRepositoryInterface $customerRepository
    ) {}

    public function handle(ServiceOrderQuoteSent $event): void
    {
        $serviceOrder = $event->serviceOrder;
        $customer = $this->customerRepository->findById($serviceOrder->customerId);

        if (! $customer) {
            return;
        }

        // Links gerados pelo billing-service (dono do orçamento).
        $approvalLink = $event->approvalUrl;
        $rejectLink = $event->rejectUrl;

        $notification = new EntityNotification(
            Str::uuid()->toString(),
            $customer->email,
            NotificationType::EMAIL,
            'Orçamento da sua ordem de serviço disponível',
            "Olá {$customer->name}, o orçamento da sua ordem de serviço foi gerado. Total: R$ {$serviceOrder->totalBudget}.\n\nClique em um dos links abaixo para decidir:\nAprovar: {$approvalLink}\nRecusar: {$rejectLink}",
            NotificationStatus::PENDING,
            new \DateTimeImmutable
        );

        $this->notificationRepository->save($notification);
        try {
            $this->notificationService->send($notification);
            $notification->markAsSent();
        } catch (\Throwable $th) {
            $notification->markAsFailed();
        }
        $this->notificationRepository->save($notification);
    }
}
