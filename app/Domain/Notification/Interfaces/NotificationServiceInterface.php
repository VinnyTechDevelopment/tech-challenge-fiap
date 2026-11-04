<?php

namespace App\Domain\Notification\Interfaces;

use App\Domain\Notification\Entities\Notification as NotificationEntity;

interface NotificationServiceInterface
{
    public function send(NotificationEntity $notification): void;
}
