<?php

declare(strict_types=1);

namespace App\Domain\Notification\Entities;

use App\Domain\Notification\ValueObjects\NotificationStatus;
use App\Domain\Notification\ValueObjects\NotificationType;
use DateTimeImmutable;

class Notification
{
    public function __construct(
        private readonly string $id,
        private readonly string $recipientId, // ID do Customer ou outro domínio
        private readonly NotificationType $type, // E-mail, SMS, Push
        private string $subject,
        private string $content,
        private NotificationStatus $status,
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $sentAt = null
    ) {}

    // Getters
    public function getId(): string
    {
        return $this->id;
    }

    public function getRecipientId(): string
    {
        return $this->recipientId;
    }

    public function getStatus(): NotificationStatus
    {
        return $this->status;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function getType(): NotificationType
    {
        return $this->type;
    }

    // Métodos de Negócio (Comportamento)
    public function markAsSent(): void
    {
        $this->status = NotificationStatus::SENT;
        $this->sentAt = new DateTimeImmutable;
    }

    public function markAsFailed(): void
    {
        $this->status = NotificationStatus::FAILED;
    }
}
