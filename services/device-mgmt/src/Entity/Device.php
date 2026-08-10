<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\Table(name: 'devices')]
#[ORM\UniqueConstraint(name: 'uniq_devices_dev_eui', columns: ['dev_eui'])]
class Device
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: DeviceGroup::class, inversedBy: 'devices')]
    #[ORM\JoinColumn(name: 'group_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?DeviceGroup $group = null;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: DeviceProtocol::class)]
    private DeviceProtocol $protocol;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    private ?string $devEui = null;

    #[ORM\Column(name: 'api_key_hash', type: Types::STRING, length: 64, nullable: true)]
    private ?string $apiKeyHash = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $metadata = [];

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $enabled = true;

    #[ORM\Column(name: 'last_seen_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastSeenAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name, DeviceProtocol $protocol)
    {
        $this->id = (string) Uuid::v7();
        $this->name = $name;
        $this->protocol = $protocol;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getGroup(): ?DeviceGroup
    {
        return $this->group;
    }

    public function setGroup(?DeviceGroup $group): void
    {
        $this->group = $group;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getProtocol(): DeviceProtocol
    {
        return $this->protocol;
    }

    public function getDevEui(): ?string
    {
        return $this->devEui;
    }

    public function setDevEui(?string $devEui): void
    {
        $this->devEui = $devEui;
    }

    public function getApiKeyHash(): ?string
    {
        return $this->apiKeyHash;
    }

    public function setApiKeyHash(?string $apiKeyHash): void
    {
        $this->apiKeyHash = $apiKeyHash;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(?\DateTimeImmutable $lastSeenAt): void
    {
        $this->lastSeenAt = $lastSeenAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
