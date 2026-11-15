<?php

namespace App\Domain\Notification\ValueObjects;

enum NotificationType: string
{
    case EMAIL = 'email';
    case SMS = 'sms';
    case PUSH = 'push';
}
