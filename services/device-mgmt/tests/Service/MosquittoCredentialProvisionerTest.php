<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Device;
use App\Entity\DeviceProtocol;
use App\Service\BrokerProvisioningException;
use App\Service\MosquittoCredentialProvisioner;
use PHPUnit\Framework\TestCase;

final class MosquittoCredentialProvisionerTest extends TestCase
{
    public function testProvisionAddsUserAndRevokeRemovesIt(): void
    {
        if (!file_exists('/usr/bin/mosquitto_passwd')) {
            self::markTestSkipped('mosquitto_passwd binary not available.');
        }

        $file = tempnam(sys_get_temp_dir(), 'mqttpasswd');
        self::assertIsString($file);
        file_put_contents($file, '');

        try {
            $provisioner = new MosquittoCredentialProvisioner($file);
            $device = new Device('pump', DeviceProtocol::Mqtt);

            $provisioner->provision($device, 'dk_secret-password');

            self::assertMatchesRegularExpression('(^'.preg_quote($device->getId(), '(').':)m', (string) file_get_contents($file));

            $provisioner->revoke($device);

            self::assertStringNotContainsString($device->getId(), (string) file_get_contents($file));
        } finally {
            @unlink($file);
        }
    }

    public function testProvisionWithoutConfiguredFileThrows(): void
    {
        $provisioner = new MosquittoCredentialProvisioner('');

        $this->expectException(BrokerProvisioningException::class);
        $this->expectExceptionMessage('MQTT_PASSWD_FILE');

        $provisioner->provision(new Device('pump', DeviceProtocol::Mqtt), 'dk_secret-password');
    }
}
