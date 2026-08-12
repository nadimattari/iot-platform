<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Command;
use App\Entity\CommandRepository;
use App\Entity\CommandStatus;
use App\Entity\Device;
use App\Entity\DeviceProtocol;

/**
 * Unified command entry point: dispatches a command to the transport that
 * matches the device protocol (LoRaWAN via ChirpStack, MQTT via the broker)
 * and records the command lifecycle in the `commands` table.
 */
final class CommandService
{
    public function __construct(
        private readonly DownlinkService $downlinks,
        private readonly MqttCommandService $mqttCommands,
        private readonly CommandRepository $commands,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function history(?Device $device, ?CommandStatus $status, int $page, int $limit): array
    {
        $result = $this->commands->search($device, $status, $page, $limit);

        return [
            'items' => array_map(serialize_command(...), $result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    public function send(Device $device, array $body): Command
    {
        if (!$device->isEnabled()) {
            throw new \InvalidArgumentException('Device is disabled.');
        }

        return match ($device->getProtocol()) {
            DeviceProtocol::LoRaWan => $this->downlinks->enqueue(
                $device,
                isset($body['f_port']) ? (int) $body['f_port'] : 10,
                (bool) ($body['confirmed'] ?? false),
                isset($body['data']) && is_string($body['data']) ? $body['data'] : null,
                isset($body['object']) && is_array($body['object']) ? $body['object'] : null,
            ),
            DeviceProtocol::Mqtt => $this->mqttCommands->send($device, $this->requirePayload($body)),
            default => throw new \InvalidArgumentException(
                sprintf('Commands are not supported for %s devices.', $device->getProtocol()->value),
            ),
        };
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function requirePayload(array $body): array
    {
        $payload = $body['payload'] ?? null;
        if (!is_array($payload) || [] === $payload) {
            throw new \InvalidArgumentException('payload is required for MQTT commands.');
        }

        return $payload;
    }
}
