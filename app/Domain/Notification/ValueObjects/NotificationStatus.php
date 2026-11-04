<?php

namespace App\Domain\Notification\ValueObjects;

enum NotificationStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';
}
