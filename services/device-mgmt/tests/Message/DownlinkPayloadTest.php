<?php

declare(strict_types=1);

namespace App\Tests\Message;

use App\Message\DownlinkPayload;
use PHPUnit\Framework\TestCase;

final class DownlinkPayloadTest extends TestCase
{
    public function testBuildsJsonWithBase64Data(): void
    {
        $payload = new DownlinkPayload(
            id: '019febd1-0000-7000-8000-000000000001',
            devEui: '70B3D5499E320001',
            fPort: 10,
            confirmed: true,
            data: 'aGVsbG8=',
        );

        self::assertSame([
            'id' => '019febd1-0000-7000-8000-000000000001',
            'devEui' => '70B3D5499E320001',
            'confirmed' => true,
            'fPort' => 10,
            'data' => 'aGVsbG8=',
        ], json_decode($payload->toJson(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testBuildsJsonWithObject(): void
    {
        $payload = new DownlinkPayload(
            id: '019febd1-0000-7000-8000-000000000001',
            devEui: '70B3D5499E320001',
            fPort: 5,
            confirmed: false,
            object: ['relay' => true],
        );

        self::assertSame([
            'id' => '019febd1-0000-7000-8000-000000000001',
            'devEui' => '70B3D5499E320001',
            'confirmed' => false,
            'fPort' => 5,
            'object' => ['relay' => true],
        ], json_decode($payload->toJson(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testBuildsDownlinkTopic(): void
    {
        $payload = new DownlinkPayload(
            id: '019febd1-0000-7000-8000-000000000001',
            devEui: '70B3D5499E320001',
            fPort: 10,
            confirmed: false,
            data: 'aGVsbG8=',
        );

        self::assertSame(
            'application/30248d5b-b17c-4a6c-8069-927c17486608/device/70B3D5499E320001/command/down',
            $payload->topic('30248d5b-b17c-4a6c-8069-927c17486608'),
        );
    }

    public function testRejectsMalformedDevEui(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('dev_eui must be 16 hex characters.');

        new DownlinkPayload('x', 'not-a-dev-eui', 10, false, 'aGVsbG8=');
    }

    public function testRejectsOutOfRangeFPort(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('f_port must be between 1 and 255.');

        new DownlinkPayload('x', '70B3D5499E320001', 0, false, 'aGVsbG8=');
    }

    public function testRejectsMissingDataAndObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Provide exactly one of data (base64) or object.');

        new DownlinkPayload('x', '70B3D5499E320001', 10, false);
    }

    public function testRejectsBothDataAndObject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Provide exactly one of data (base64) or object.');

        new DownlinkPayload('x', '70B3D5499E320001', 10, false, 'aGVsbG8=', ['relay' => true]);
    }

    public function testRejectsInvalidBase64Data(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('data must be valid base64.');

        new DownlinkPayload('x', '70B3D5499E320001', 10, false, 'not@base64!');
    }
}
