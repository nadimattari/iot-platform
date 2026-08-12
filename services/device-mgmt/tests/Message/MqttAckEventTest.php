<?php

declare(strict_types=1);

namespace App\Tests\Message;

use App\Message\MqttAckEvent;
use PHPUnit\Framework\TestCase;

final class MqttAckEventTest extends TestCase
{
    public function testFromJsonParsesCommandId(): void
    {
        $event = MqttAckEvent::fromJson('{"id":"019ff422-ea57-7ed5-8f0b-834cac934a17"}');

        self::assertSame('019ff422-ea57-7ed5-8f0b-834cac934a17', $event->commandId);
    }

    public function testFromJsonRejectsMissingId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ack event missing id.');
        MqttAckEvent::fromJson('{"status":"ok"}');
    }

    public function testFromJsonRejectsNonObjectPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MqttAckEvent::fromJson('["not","an","object"]');
    }
}
