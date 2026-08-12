<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Command;
use App\Entity\CommandStatus;
use App\Entity\CommandType;
use App\Entity\Device;
use App\Entity\DeviceProtocol;
use App\Message\MqttCommandPayload;
use App\Message\MqttCommandPublisherInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Sends an MQTT command to a direct-MQTT device. The command UUID doubles as
 * the correlation id: it is published inside the `devices/{id}/down` payload
 * and the device echoes it back on `devices/{id}/ack`.
 */
final class MqttCommandService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MqttCommandPublisherInterface $publisher,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(Device $device, array $payload): Command
    {
        if (DeviceProtocol::Mqtt !== $device->getProtocol()) {
            throw new \InvalidArgumentException('MQTT commands are only supported for MQTT devices.');
        }
        if (!$device->isEnabled()) {
            throw new \InvalidArgumentException('Device is disabled.');
        }

        $command = new Command($device, CommandType::MqttMessage);
        $command->setPayload(['payload' => $payload]);
        $command->setQueueItemId($command->getId());

        $wire = new MqttCommandPayload($command->getId(), $payload);

        try {
            $this->publisher->publish($wire->topic($device->getId()), $wire->toJson());
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
