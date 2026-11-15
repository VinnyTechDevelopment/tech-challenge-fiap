<?php

namespace App\Infrastructure\Persistence\Eloquent\Repositories;

use App\Domain\Notification\Entities\Notification as NotificationEntity;
use App\Domain\Notification\Interfaces\NotificationRepositoryInterface;
use App\Domain\Notification\ValueObjects\NotificationStatus;
use App\Domain\Notification\ValueObjects\NotificationType;
use App\Infrastructure\Persistence\Eloquent\Models\NotificationModel;

class NotificationRepositoryEloquent implements NotificationRepositoryInterface
{
    public function save(NotificationEntity $notification): void
    {
        NotificationModel::updateOrCreate(
            ['id' => $notification->getId()],
            [
                'recipient_id' => $notification->getRecipientId(),
                'type' => $notification->getType()->value,
                'subject' => $notification->getSubject(),
                'content' => $notification->getContent(),
                'status' => $notification->getStatus()->value,
                'sent_at' => $notification->getSentAt()?->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function findById(string $id): ?NotificationEntity
    {
        $model = NotificationModel::find($id);
        if (! $model) {
            return null;
        }

        return new NotificationEntity(
            $model->id,
            $model->recipient_id,
            NotificationType::from($model->type),
            $model->subject,
            $model->content,
            NotificationStatus::from($model->status),
            new \DateTimeImmutable($model->created_at),
            $model->sent_at ? new \DateTimeImmutable($model->sent_at) : null
        );
    }

    public function paginate(int $page, int $perPage): array
    {
        return NotificationModel::query()
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->toArray();
    }
}
