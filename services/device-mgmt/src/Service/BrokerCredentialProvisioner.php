<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Device;

/**
 * Provisions and revokes MQTT broker credentials for a device.
 *
 * The broker authenticates every MQTT client: a device logs in with its device
 * id as the username and the (single-return) API key as the password, and is
 * only allowed to publish/subscribe under devices/<device-id>/#.
 */
interface BrokerCredentialProvisioner
{
    public function provision(Device $device, string $password): void;

    public function revoke(Device $device): void;
}
