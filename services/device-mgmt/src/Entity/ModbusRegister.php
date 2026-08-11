<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ModbusRegisterRepository::class)]
#[ORM\Table(name: 'modbus_register_config')]
#[ORM\UniqueConstraint(name: 'uniq_modbus_register_config_device_name', columns: ['device_id', 'name'])]
class ModbusRegister
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(name: 'device_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    /** 0-based holding register start address. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $address;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $datatype;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $byteorder;

    #[ORM\Column(type: Types::FLOAT)]
    private float $scale;

    #[ORM\Column(name: 'interval_secs', type: Types::INTEGER)]
    private int $intervalSecs;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Device $device,
        string $name,
        int $address,
        string $datatype,
        string $byteorder,
        float $scale,
        int $intervalSecs,
    ) {
        $this->id = (string) Uuid::v7();
        $this->device = $device;
        $this->setName($name);
        $this->setAddress($address);
        $this->setDatatype($datatype);
        $this->setByteorder($byteorder);
        $this->setScale($scale);
        $this->setIntervalSecs($intervalSecs);
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDevice(): Device
    {
        return $this->device;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getAddress(): int
    {
        return $this->address;
    }

    public function setAddress(int $address): void
    {
        $this->address = $address;
    }

    public function getDatatype(): string
    {
        return $this->datatype;
    }

    public function setDatatype(string $datatype): void
    {
        $this->datatype = $datatype;
    }

    public function getByteorder(): string
    {
        return $this->byteorder;
    }

    public function setByteorder(string $byteorder): void
    {
        $this->byteorder = $byteorder;
    }

    public function getScale(): float
    {
        return $this->scale;
    }

    public function setScale(float $scale): void
    {
        $this->scale = $scale;
    }

    public function getIntervalSecs(): int
    {
        return $this->intervalSecs;
    }

    public function setIntervalSecs(int $intervalSecs): void
    {
        $this->intervalSecs = $intervalSecs;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
