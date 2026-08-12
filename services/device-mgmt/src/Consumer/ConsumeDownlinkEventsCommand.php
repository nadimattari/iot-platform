<?php

declare(strict_types=1);

namespace App\Consumer;

use App\Message\DownlinkAckEvent;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Long-running consumer for ChirpStack `event/ack` and `event/txack` events.
 * Each reconnect recreates the client (and therefore re-subscribes), since MQTT
 * subscriptions are per-session.
 */
#[AsCommand(name: 'app:consume-downlink-events', description: 'Consume ChirpStack downlink ACK/TxACK events and update command status.')]
final class ConsumeDownlinkEventsCommand extends Command
{
    public function __construct(
        private readonly DownlinkAckHandler $handler,
        private readonly LoggerInterface $logger,
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $applicationId,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('' === $this->username) {
            throw new \RuntimeException('MQTT_DOWNLINK_USERNAME is not configured.');
        }
        if ('' === $this->applicationId) {
            throw new \RuntimeException('CHIRPSTACK_APPLICATION_ID is not configured.');
        }

        $output->writeln(sprintf('Subscribing to downlink events for application %s.', $this->applicationId));

        while (true) {
            try {
                $this->consume();
            } catch (\Throwable $e) {
                $this->logger->error('Downlink event consumer disconnected: {message}', ['message' => $e->getMessage()]);
                usleep(1_000_000);
            }
        }

        return Command::SUCCESS;
    }

    private function consume(): void
    {
        $client = new MqttClient($this->host, $this->port, 'device-mgmt-events', MqttClient::MQTT_3_1_1);
        $client->connect(
            (new ConnectionSettings())->setUsername($this->username)->setPassword($this->password),
            true,
        );

        foreach (['ack', 'txack'] as $event) {
            $client->subscribe(sprintf('application/%s/device/+/event/%s', $this->applicationId, $event), $this->messageCallback());
        }

        $client->loop();
    }

    /**
     * php-mqtt v2.x passes the raw message content (string), not a Message
     * object, to subscription callbacks.
     */
    private function messageCallback(): \Closure
    {
        return fn (string $topic, string $content) => $this->handle($topic, $content);
    }

    private function handle(string $topic, string $content): void
    {
        $eventName = str_contains($topic, '/event/ack') ? 'ack' : 'txack';

        try {
            $event = DownlinkAckEvent::fromJson($content);
            $command = 'ack' === $eventName ? $this->handler->handleAck($event) : $this->handler->handleTxAck($event);
            $this->logger->info('Processed {event} for queue item {queueItemId} (command {command}).', [
                'event' => $eventName,
                'queueItemId' => $event->queueItemId,
                'command' => null !== $command ? $command->getId() : 'unknown',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not process {event} event on {topic}: {message}', [
                'event' => $eventName,
                'topic' => $topic,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
