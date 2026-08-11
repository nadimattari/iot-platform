<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Device;
use App\Entity\ModbusRegister;
use App\Entity\ModbusRegisterRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @return array{name: string, address: int, datatype: string, byteorder: string, scale: float, interval_secs: int}
 */
function serialize_register(ModbusRegister $register): array
{
    return [
        'name' => $register->getName(),
        'address' => $register->getAddress(),
        'datatype' => $register->getDatatype(),
        'byteorder' => $register->getByteorder(),
        'scale' => $register->getScale(),
        'interval_secs' => $register->getIntervalSecs(),
    ];
}

final class RegisterService
{
    private const DATATYPES = ['uint8', 'int8', 'uint16', 'int16', 'uint32', 'int32', 'float32', 'float64'];
    private const BYTE_ORDERS = ['big', 'little', 'byte_swap', 'byte_word_swap'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ModbusRegisterRepository $registers,
    ) {
    }

    /**
     * @return list<array{name: string, address: int, datatype: string, byteorder: string, scale: float, interval_secs: int}>
     */
    public function listForDevice(Device $device): array
    {
        return array_map(serialize_register(...), $this->registers->findByDevice($device));
    }

    /**
     * Replace the full register set for a device. Each entry is validated;
     * duplicate names within the payload are rejected.
     *
     * @param list<array<string, mixed>> $entries
     *
     * @return list<array{name: string, address: int, datatype: string, byteorder: string, scale: float, interval_secs: int}>
     */
    public function replaceForDevice(Device $device, array $entries): array
    {
        $normalized = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException('each register must be an object.');
            }
            $register = $this->normalizeEntry($entry);
            if (isset($seen[$register['name']])) {
                throw new \InvalidArgumentException(sprintf('duplicate register name: %s', $register['name']));
            }
            $seen[$register['name']] = true;
            $normalized[] = $register;
        }

        $this->registers->deleteForDevice($device);
        foreach ($normalized as $entry) {
            $this->em->persist(new ModbusRegister(
                $device,
                $entry['name'],
                $entry['address'],
                $entry['datatype'],
                $entry['byteorder'],
                $entry['scale'],
                $entry['interval_secs'],
            ));
        }
        $this->em->flush();

        return $this->listForDevice($device);
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return array{name: string, address: int, datatype: string, byteorder: string, scale: float, interval_secs: int}
     */
    private function normalizeEntry(array $entry): array
    {
        $name = $entry['name'] ?? null;
        if (!is_string($name) || '' === trim($name)) {
            throw new \InvalidArgumentException('register name is required.');
        }
        $name = trim($name);
        if (strlen($name) > 255) {
            throw new \InvalidArgumentException('register name is too long (max 255 characters).');
        }

        $address = $entry['address'] ?? null;
        if (!is_int($address) && !(is_string($address) && ctype_digit($address))) {
            throw new \InvalidArgumentException(sprintf('register "%s": address must be a non-negative integer.', $name));
        }
        $address = (int) $address;
        if ($address < 0) {
            throw new \InvalidArgumentException(sprintf('register "%s": address must be a non-negative integer.', $name));
        }

        $datatype = $entry['datatype'] ?? null;
        if (!in_array($datatype, self::DATATYPES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'register "%s": datatype must be one of: %s.',
                $name,
                implode(', ', self::DATATYPES),
            ));
        }

        $byteorder = $entry['byteorder'] ?? 'big';
        if (!in_array($byteorder, self::BYTE_ORDERS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'register "%s": byteorder must be one of: %s.',
                $name,
                implode(', ', self::BYTE_ORDERS),
            ));
        }

        $scale = $entry['scale'] ?? 1.0;
        if (!is_numeric($scale) || (float) $scale <= 0.0) {
            throw new \InvalidArgumentException(sprintf('register "%s": scale must be a positive number.', $name));
        }

        $interval = $entry['interval_secs'] ?? 60;
        if (!is_int($interval) && !(is_string($interval) && ctype_digit($interval))) {
            throw new \InvalidArgumentException(sprintf('register "%s": interval_secs must be a positive integer.', $name));
        }
        $interval = (int) $interval;
        if ($interval < 1) {
            throw new \InvalidArgumentException(sprintf('register "%s": interval_secs must be a positive integer.', $name));
        }

        return [
            'name' => $name,
            'address' => $address,
            'datatype' => $datatype,
            'byteorder' => $byteorder,
            'scale' => (float) $scale,
            'interval_secs' => $interval,
        ];
    }
}
