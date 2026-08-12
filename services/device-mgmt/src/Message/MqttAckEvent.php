<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Envelope received on `devices/{deviceId}/ack`: the device echoes the
 * command `id` published on `devices/{deviceId}/down`.
 */
final class MqttAckEvent
{
    public function __construct(
        public readonly string $commandId,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('ack event payload must be a JSON object.');
        }

        $id = $data['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new \InvalidArgumentException('ack event missing id.');
        }

        return new self($id);
    }
}
