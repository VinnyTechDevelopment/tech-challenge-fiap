<?php

namespace App\Application\Notification\UseCases;

use App\Application\Notification\DTOs\ListNotificationDTO;
use App\Domain\Notification\Interfaces\NotificationRepositoryInterface;

class ListNotificationUseCase
{
    public function __construct(
        private NotificationRepositoryInterface $repository
    ) {}

    public function execute(ListNotificationDTO $dto): array
    {
        $perPage = $dto->perPage;

        if ($perPage <= 0) {
            $perPage = 10;
        }

        return $this->repository->paginate($dto->page, $perPage);
    }
}
