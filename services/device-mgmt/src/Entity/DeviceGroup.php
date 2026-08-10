<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DeviceGroupRepository::class)]
#[ORM\Table(name: 'device_groups')]
#[ORM\UniqueConstraint(name: 'uniq_device_groups_name', columns: ['name'])]
class DeviceGroup
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    /** @var Collection<int, Device> */
    #[ORM\OneToMany(targetEntity: Device::class, mappedBy: 'group')]
    private Collection $devices;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $name)
    {
        $this->id = (string) Uuid::v7();
        $this->name = $name;
        $this->devices = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    /**
     * @return Collection<int, Device>
     */
    public function getDevices(): Collection
    {
        return $this->devices;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
