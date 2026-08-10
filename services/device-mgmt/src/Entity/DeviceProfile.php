<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: DeviceProfileRepository::class)]
#[ORM\Table(name: 'device_profiles')]
#[ORM\UniqueConstraint(name: 'uniq_device_profiles_name', columns: ['name'])]
class DeviceProfile
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    /**
     * Blueprint of telemetry fields: [{ "field": "temperature", "type": "float" }].
     *
     * @var list<array<string, mixed>>
     */
    #[ORM\Column(name: 'field_defs', type: Types::JSON, options: ['jsonb' => true])]
    private array $fieldDefs;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<array<string, mixed>> $fieldDefs
     */
    public function __construct(string $name, array $fieldDefs)
    {
        $this->id = (string) Uuid::v7();
        $this->name = $name;
        $this->fieldDefs = $fieldDefs;
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
     * @return list<array<string, mixed>>
     */
    public function getFieldDefs(): array
    {
        return $this->fieldDefs;
    }

    /**
     * @param list<array<string, mixed>> $fieldDefs
     */
    public function setFieldDefs(array $fieldDefs): void
    {
        $this->fieldDefs = $fieldDefs;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
