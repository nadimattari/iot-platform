<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Device;
use App\Entity\DeviceGroup;
use App\Entity\DeviceGroupRepository;
use App\Entity\DeviceProtocol;
use App\Entity\DeviceRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @return array<string, mixed>
 */
function serialize_device(Device $device): array
{
    return [
        'id' => $device->getId(),
        'name' => $device->getName(),
        'protocol' => $device->getProtocol()->value,
        'group_id' => $device->getGroup()?->getId(),
        'dev_eui' => $device->getDevEui(),
        'metadata' => $device->getMetadata(),
        'enabled' => $device->isEnabled(),
        'last_seen_at' => $device->getLastSeenAt()?->format(\DateTimeInterface::ATOM),
        'created_at' => $device->getCreatedAt()->format(\DateTimeInterface::ATOM),
    ];
}

final class DeviceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DeviceRepository $devices,
        private readonly DeviceGroupRepository $groups,
        private readonly ApiKeyGenerator $apiKeys,
    ) {
    }

    /**
     * @return array{device: Device, apiKey: ?string} $apiKey is only present
     *                                                when the protocol uses credentials.
     */
    public function create(string $name, DeviceProtocol $protocol, ?string $groupId, array $metadata): array
    {
        $device = new Device($name, $protocol);
        $device->setMetadata($metadata);
        $this->assignGroup($device, $groupId);

        $apiKey = null;
        if ($protocol->requiresApiKey()) {
            $apiKey = $this->provisionApiKey($device);
        }

        $this->em->persist($device);
        $this->em->flush();

        return ['device' => $device, 'apiKey' => $apiKey];
    }

    public function update(Device $device, ?string $name, ?string $groupId, ?array $metadata): Device
    {
        if (null !== $name) {
            $device->setName($name);
        }
        if (null !== $groupId) {
            $this->assignGroup($device, $groupId);
        }
        if (null !== $metadata) {
            $device->setMetadata($metadata);
        }

        $this->em->flush();

        return $device;
    }

    public function setEnabled(Device $device, bool $enabled): Device
    {
        $device->setEnabled($enabled);
        $this->em->flush();

        return $device;
    }

    public function delete(Device $device): void
    {
        $this->em->remove($device);
        $this->em->flush();
    }

    /**
     * @return array{device: Device, apiKey: ?string}
     */
    public function claim(Device $device, ?string $devEui): array
    {
        $apiKey = null;

        if (DeviceProtocol::LoRaWan === $device->getProtocol()) {
            if (null === $devEui || '' === $devEui) {
                throw new \InvalidArgumentException('dev_eui is required for LoRaWAN devices.');
            }
            if (null !== $this->devices->findByDevEui($devEui)) {
                throw new DeviceConflictException('dev_eui is already in use.');
            }
            $device->setDevEui($devEui);
        } else {
            $apiKey = $this->provisionApiKey($device);
        }

        $this->em->flush();

        return ['device' => $device, 'apiKey' => $apiKey];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function list(?DeviceProtocol $protocol, int $page, int $limit): array
    {
        $result = $this->devices->search($protocol, $page, $limit);

        return [
            'items' => array_map(serialize_device(...), $result['items']),
            'total' => $result['total'],
            'page' => $result['page'],
            'limit' => $result['limit'],
        ];
    }

    private function assignGroup(Device $device, ?string $groupId): void
    {
        if (null === $groupId || '' === $groupId) {
            $device->setGroup(null);

            return;
        }

        $group = $this->groups->find($groupId);
        if (!$group instanceof DeviceGroup) {
            throw new \InvalidArgumentException('group_id does not exist.');
        }
        $device->setGroup($group);
    }

    private function provisionApiKey(Device $device): string
    {
        $generated = $this->apiKeys->generate();
        $device->setApiKeyHash($generated['hash']);

        return $generated['plaintext'];
    }
}
