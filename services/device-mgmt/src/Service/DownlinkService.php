<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Command;
use App\Entity\CommandStatus;
use App\Entity\CommandType;
use App\Entity\Device;
use App\Entity\DeviceProtocol;
use App\Message\DownlinkPayload;
use App\Message\DownlinkPublisherInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @return array<string, mixed>
 */
function serialize_command(Command $command): array
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

final class DownlinkService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DownlinkPublisherInterface $publisher,
        private readonly string $chirpstackApplicationId,
    ) {
    }

    /**
     * Enqueues a ChirpStack downlink and records the command lifecycle. The
     * command UUID doubles as the ChirpStack `queueItemId` so ACK/TxACK events
     * can be correlated back to the command.
     */
    public function enqueue(Device $device, int $fPort, bool $confirmed, ?string $dataBase64 = null, ?array $object = null): Command
    {
        if (DeviceProtocol::LoRaWan !== $device->getProtocol()) {
            throw new \InvalidArgumentException('Downlinks are only supported for LoRaWAN devices.');
        }
        if (!$device->isEnabled()) {
            throw new \InvalidArgumentException('Device is disabled.');
        }
        if (null === $device->getDevEui()) {
            throw new \InvalidArgumentException('Device has no dev_eui; claim it first.');
        }

        $command = new Command($device, CommandType::LoraWanDownlink);
        $command->setFPort($fPort);
        $command->setConfirmed($confirmed);
        $command->setQueueItemId($command->getId());
        $command->setPayload(null !== $dataBase64 ? ['data' => $dataBase64] : ['object' => $object]);

        $payload = new DownlinkPayload($command->getId(), $device->getDevEui(), $fPort, $confirmed, $dataBase64, $object);

        try {
            $this->publisher->publish($payload->topic($this->chirpstackApplicationId), $payload->toJson());
            $command->setStatus(CommandStatus::Sent);
        } catch (\Throwable $e) {
            $command->setStatus(CommandStatus::Failed);
            $command->setError($e->getMessage());
        }

        $this->em->persist($command);
        $this->em->flush();

        return $command;
    }
}
