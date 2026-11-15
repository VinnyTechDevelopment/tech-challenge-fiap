<?php

namespace Tests\Unit\Notifications;

use App\Notifications\GenericNotification;
use Illuminate\Notifications\Messages\MailMessage;
use PHPUnit\Framework\TestCase;

class GenericNotificationTest extends TestCase
{
    public function test_via_returns_mail_channel(): void
    {
        $notification = new GenericNotification;

        $this->assertSame(['mail'], $notification->via(new \stdClass));
    }

    public function test_to_mail_returns_mail_message(): void
    {
        $notification = new GenericNotification;

        $this->assertInstanceOf(MailMessage::class, $notification->toMail(new \stdClass));
    }

    public function test_to_array_returns_array(): void
    {
        $notification = new GenericNotification;

        $this->assertIsArray($notification->toArray(new \stdClass));
    }
}
