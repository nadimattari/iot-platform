<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Device;
use Symfony\Component\Process\Process;

/**
 * Manages Mosquitto credentials via the mosquitto_passwd binary.
 *
 * device-mgmt is the only writer of the shared mosquitto.passwd file, which is
 * bind-mounted into both this container and the broker container. Mosquitto
 * picks up changes on SIGHUP, which the broker entrypoint sends whenever the
 * file changes.
 */
final class MosquittoCredentialProvisioner implements BrokerCredentialProvisioner
{
    public function __construct(
        private readonly string $passwdFile,
        private readonly string $binary = 'mosquitto_passwd',
    ) {
    }

    public function provision(Device $device, string $password): void
    {
        $this->assertConfigured();
        $this->run(['-b', $this->passwdFile, $device->getId(), $password]);
        $this->fixPermissions();
    }

    public function revoke(Device $device): void
    {
        $this->assertConfigured();

        $current = @file_get_contents($this->passwdFile);
        if (false === $current) {
            return;
        }
        if (!preg_match('(^'.preg_quote($device->getId(), '()').':)m', $current)) {
            return;
        }

        $this->run(['-D', $this->passwdFile, $device->getId()]);
        $this->fixPermissions();
    }

    /**
     * mosquitto rewrites the file via temp+rename, losing the group: mosquitto
     * (gid 1883) must keep read access, so restore the shared mqtt group and a
     * group-readable mode. www-data is a member of the mqtt group, so chgrp is
     * allowed; failures are ignored outside the compose setup.
     */
    private function fixPermissions(): void
    {
        @chgrp($this->passwdFile, 'mqtt');
        @chmod($this->passwdFile, 0640);
    }

    private function assertConfigured(): void
    {
        if ('' === $this->passwdFile) {
            throw new BrokerProvisioningException('MQTT broker credentials file is not configured (MQTT_PASSWD_FILE).');
        }
    }

    /**
     * @param list<string> $args
     */
    private function run(array $args): void
    {
        $process = new Process([$this->binary, ...$args]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new BrokerProvisioningException(
                sprintf('mosquitto_passwd failed (exit %d): %s', $process->getExitCode(), trim($process->getErrorOutput())),
            );
        }
    }
}
