<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Command;

final class CommandSerializer
{
    /**
     * @return array<string, mixed>
     */
    public static function serialize(Command $command): array
    {
        return [
            'id' => $command->getId(),
            'device_id' => $command->getDevice()->getId(),
            'type' => $command->getType()->value,
            'status' => $command->getStatus()->value,
            'payload' => $command->getPayload(),
            'confirmed' => $command->isConfirmed(),
            'f_port' => $command->getFPort(),
            'queue_item_id' => $command->getQueueItemId(),
            'error' => $command->getError(),
            'created_at' => $command->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $command->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
