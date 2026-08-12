<?php

declare(strict_types=1);

namespace App\Message;

/**
 * ChirpStack v4 DownlinkCommand payload sent on the `command/down` topic.
 *
 * @see https://www.chirpstack.io/docs/chirpstack/integrations/mqtt.html
 */
final class DownlinkPayload
{
    public function __construct(
        private readonly string $id,
        private readonly string $devEui,
        private readonly int $fPort,
        private readonly bool $confirmed,
        private readonly ?string $data = null,
        private readonly ?array $object = null,
    ) {
        if (1 !== preg_match('/^[0-9A-Fa-f]{16}$/', $devEui)) {
            throw new \InvalidArgumentException('dev_eui must be 16 hex characters.');
        }
        if ($fPort < 1 || $fPort > 255) {
            throw new \InvalidArgumentException('f_port must be between 1 and 255.');
        }
        if (($data === null) === ($object === null)) {
            throw new \InvalidArgumentException('Provide exactly one of data (base64) or object.');
        }
        if (null !== $data && ('' === $data || false === base64_decode($data, true))) {
            throw new \InvalidArgumentException('data must be valid base64.');
        }
    }

    public function topic(string $applicationId): string
    {
        return sprintf('application/%s/device/%s/command/down', $applicationId, $this->devEui);
    }

    public function toJson(): string
    {
        $payload = [
            'id' => $this->id,
            'devEui' => $this->devEui,
            'confirmed' => $this->confirmed,
            'fPort' => $this->fPort,
        ];

        if (null !== $this->data) {
            $payload['data'] = $this->data;
        } else {
            $payload['object'] = $this->object;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
