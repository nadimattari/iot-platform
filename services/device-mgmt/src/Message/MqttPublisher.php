<?php

declare(strict_types=1);

namespace App\Message;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/**
 * Publishes command messages (LoRaWAN downlinks, MQTT device commands) to the
 * broker over MQTT 3.1.1. A fresh connection is opened per publish: the
 * command APIs are low-rate and this avoids stale-connection handling entirely.
 */
final class MqttPublisher implements DownlinkPublisherInterface, MqttCommandPublisherInterface
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    public function publish(string $topic, string $payload): void
    {
        if ('' === $this->username) {
            throw new \RuntimeException('MQTT_DOWNLINK_USERNAME is not configured.');
        }

        $client = new MqttClient($this->host, $this->port, 'device-mgmt-pub', MqttClient::MQTT_3_1_1);
        try {
            $client->connect(
                (new ConnectionSettings())->setUsername($this->username)->setPassword($this->password),
            );
            $client->publish($topic, $payload);
        } finally {
            if ($client->isConnected()) {
                $client->disconnect();
            }
        }
    }
}
