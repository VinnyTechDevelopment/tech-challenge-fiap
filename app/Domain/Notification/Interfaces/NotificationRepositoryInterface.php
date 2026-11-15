<?php

namespace App\Domain\Notification\Interfaces;

use App\Domain\Notification\Entities\Notification as NotificationEntity;

interface NotificationRepositoryInterface
{
    public function save(NotificationEntity $notification): void;

    public function findById(string $id): ?NotificationEntity;

    public function paginate(int $page, int $perPage): array;
}
