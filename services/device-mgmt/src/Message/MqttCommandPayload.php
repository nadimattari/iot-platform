<?php

declare(strict_types=1);

namespace App\Message;

/**
 * MQTT command envelope published on `devices/{deviceId}/down`. Carries the
 * command UUID (`id`) so a device can echo it back on `devices/{deviceId}/ack`
 * for correlation, mirroring how ChirpStack correlates via `queueItemId`.
 */
final class MqttCommandPayload
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private readonly string $id,
        private readonly array $payload,
    ) {
        if ('' === $this->id) {
            throw new \InvalidArgumentException('id is required.');
        }
        if ([] === $this->payload) {
            throw new \InvalidArgumentException('payload must be a non-empty JSON object.');
        }
    }

    public function topic(string $deviceId): string
    {
        return sprintf('devices/%s/down', $deviceId);
    }

    public function toJson(): string
    {
        return json_encode(['id' => $this->id, 'payload' => $this->payload], JSON_THROW_ON_ERROR);
    }
}
