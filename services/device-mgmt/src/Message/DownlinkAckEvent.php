<?php

declare(strict_types=1);

namespace App\Message;

/**
 * ChirpStack `event/ack` (device acknowledgement or timeout) and `event/txack`
 * (gateway accepted the frame for transmission) envelope, reduced to the
 * fields needed for command status tracking.
 *
 * @see https://www.chirpstack.io/docs/chirpstack/integrations/mqtt.html
 */
final class DownlinkAckEvent
{
    public function __construct(
        public readonly string $queueItemId,
        public readonly bool $acknowledged,
        public readonly ?\DateTimeImmutable $time = null,
    ) {
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('ack event payload must be a JSON object.');
        }

        $queueItemId = $data['queueItemId'] ?? null;
        if (!is_string($queueItemId) || '' === $queueItemId) {
            throw new \InvalidArgumentException('ack event missing queueItemId.');
        }

        $time = null;
        if (isset($data['time']) && is_string($data['time'])) {
            $time = new \DateTimeImmutable($data['time']);
        }

        return new self($queueItemId, (bool) ($data['acknowledged'] ?? false), $time);
    }
}
