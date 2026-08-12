<?php

declare(strict_types=1);

namespace App\Tests\Message;

use App\Message\MqttCommandPayload;
use PHPUnit\Framework\TestCase;

final class MqttCommandPayloadTest extends TestCase
{
    public function testTopicUsesDeviceDownTopic(): void
    {
        $payload = new MqttCommandPayload('019ff422-ea57-7ed5-8f0b-834cac934a17', ['relay' => true]);

        self::assertSame('devices/019fe9fe-c87d-7c6e-a0d9-b7197cd962ba/down', $payload->topic('019fe9fe-c87d-7c6e-a0d9-b7197cd962ba'));
    }

    public function testToJsonCarriesIdAndPayload(): void
    {
        $payload = new MqttCommandPayload('cmd-1', ['relay' => true]);

        $decoded = json_decode($payload->toJson(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('cmd-1', $decoded['id']);
        self::assertSame(['relay' => true], $decoded['payload']);
    }

    public function testRejectsEmptyPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('payload must be a non-empty JSON object.');
        new MqttCommandPayload('cmd-1', []);
    }
}
