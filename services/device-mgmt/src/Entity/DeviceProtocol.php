<?php

declare(strict_types=1);

namespace App\Entity;

enum DeviceProtocol: string
{
    case Mqtt = 'mqtt';
    case LoRaWan = 'lorawan';
    case Modbus = 'modbus';
    case Http = 'http';

    /**
     * @return string[]
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    public function requiresApiKey(): bool
    {
        return self::LoRaWan !== $this;
    }
}
