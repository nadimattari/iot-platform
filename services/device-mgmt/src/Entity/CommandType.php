<?php

declare(strict_types=1);

namespace App\Entity;

enum CommandType: string
{
    case LoraWanDownlink = 'lorawan_downlink';
    case MqttMessage = 'mqtt_message';
}
