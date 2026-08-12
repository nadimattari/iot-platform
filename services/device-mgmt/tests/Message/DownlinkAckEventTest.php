<?php

declare(strict_types=1);

namespace App\Tests\Message;

use App\Message\DownlinkAckEvent;
use PHPUnit\Framework\TestCase;

final class DownlinkAckEventTest extends TestCase
{
    public function testParsesAcknowledgedEvent(): void
    {
        $event = DownlinkAckEvent::fromJson(json_encode([
            'queueItemId' => '019febd1-0000-7000-8000-000000000001',
            'acknowledged' => true,
            'time' => '2026-08-12T10:00:00Z',
        ], JSON_THROW_ON_ERROR));

        self::assertSame('019febd1-0000-7000-8000-000000000001', $event->queueItemId);
        self::assertTrue($event->acknowledged);
        self::assertNotNull($event->time);
    }

    public function testParsesTimeoutEventWithAcknowledgedFalse(): void
    {
        $event = DownlinkAckEvent::fromJson(json_encode([
            'queueItemId' => '019febd1-0000-7000-8000-000000000001',
            'acknowledged' => false,
        ], JSON_THROW_ON_ERROR));

        self::assertFalse($event->acknowledged);
        self::assertNull($event->time);
    }

    public function testRejectsMissingQueueItemId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ack event missing queueItemId.');

        DownlinkAckEvent::fromJson(json_encode(['acknowledged' => true], JSON_THROW_ON_ERROR));
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        DownlinkAckEvent::fromJson('{not json');
    }
}
