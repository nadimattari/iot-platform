<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Device;
use App\Service\BrokerCredentialProvisioner;

/**
 * Records provision/revoke calls instead of touching a real broker.
 */
final class FakeBrokerCredentialProvisioner implements BrokerCredentialProvisioner
{
    /** @var list<array{device: Device, password: string}> */
    public array $provisioned = [];

    /** @var list<Device> */
    public array $revoked = [];

    public function provision(Device $device, string $password): void
    {
        $this->provisioned[] = ['device' => $device, 'password' => $password];
    }

    public function revoke(Device $device): void
    {
        $this->revoked[] = $device;
    }
}
