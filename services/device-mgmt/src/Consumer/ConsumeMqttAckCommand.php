<?php

declare(strict_types=1);

namespace App\Consumer;

use App\Message\MqttAckEvent;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Long-running consumer for device ACKs on `devices/+/ack`: echoes of the
 * command id published on `devices/{deviceId}/down`. Each reconnect recreates
 * the client (and therefore re-subscribes), since MQTT subscriptions are
 * per-session.
 */
#[AsCommand(name: 'app:consume-mqtt-acks', description: 'Consume MQTT device ACKs on devices/+/ack and update command status.')]
final class ConsumeMqttAckCommand extends Command
{
    public function __construct(
        private readonly MqttAckHandler $handler,
        private readonly LoggerInterface $logger,
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('' === $this->username) {
            throw new \RuntimeException('MQTT_DOWNLINK_USERNAME is not configured.');
        }

        $output->writeln('Subscribing to device ACKs on devices/+/ack.');

        while (true) {
            try {
                $this->consume();
            } catch (\Throwable $e) {
                $this->logger->error('MQTT ack consumer disconnected: {message}', ['message' => $e->getMessage()]);
                usleep(1_000_000);
            }
        }

        return Command::SUCCESS;
    }

    private function consume(): void
    {
        $client = new MqttClient($this->host, $this->port, 'device-mgmt-acks', MqttClient::MQTT_3_1_1);
        $client->connect(
            (new ConnectionSettings())->setUsername($this->username)->setPassword($this->password),
            true,
        );

        $client->subscribe('devices/+/ack', $this->messageCallback());

        $client->loop();
    }

    /**
     * php-mqtt v2.x passes the raw message content (string), not a Message
     * object, to subscription callbacks.
     */
    private function messageCallback(): \Closure
    {
        return fn (string $topic, string $content) => $this->handle($content);
    }

    private function handle(string $content): void
    {
        try {
            $event = MqttAckEvent::fromJson($content);
            $command = $this->handler->handleAck($event);
            $this->logger->info('Processed device ack for command {command}.', [
                'command' => null !== $command ? $command->getId() : 'unknown',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not process device ack: {message}', ['message' => $e->getMessage()]);
        }
    }
}
