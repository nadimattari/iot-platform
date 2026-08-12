<?php

declare(strict_types=1);

namespace App\Message;

interface MqttCommandPublisherInterface
{
    public function publish(string $topic, string $payload): void;
}
