<?php

declare(strict_types=1);

namespace App\Message;

interface DownlinkPublisherInterface
{
    /**
     * Publishes a downlink payload to the broker. Implementations must throw
     * when the payload could not be delivered so the caller can mark the
     * command as failed.
     */
    public function publish(string $topic, string $payload): void;
}
