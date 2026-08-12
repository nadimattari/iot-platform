<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CommandRepository::class)]
#[ORM\Table(name: 'commands')]
#[ORM\Index(name: 'idx_commands_queue_item', columns: ['queue_item_id'])]
class Command
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(name: 'device_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: CommandType::class)]
    private CommandType $type;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: CommandStatus::class)]
    private CommandStatus $status;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $payload = [];

    #[ORM\Column(name: 'confirmed', type: Types::BOOLEAN)]
    private bool $confirmed = false;

    #[ORM\Column(name: 'f_port', type: Types::INTEGER)]
    private int $fPort = 0;

    #[ORM\Column(name: 'queue_item_id', type: Types::GUID, nullable: true)]
    private ?string $queueItemId = null;

    #[ORM\Column(type: Types::STRING, length: 512, nullable: true)]
    private ?string $error = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Device $device, CommandType $type)
    {
        $this->id = (string) Uuid::v7();
        $this->device = $device;
        $this->type = $type;
        $this->status = CommandStatus::Pending;
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

    public function getType(): CommandType
    {
        return $this->type;
    }

    public function getStatus(): CommandStatus
    {
        return $this->status;
    }

    public function setStatus(CommandStatus $status): void
    {
        $this->status = $status;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed;
    }

    public function setConfirmed(bool $confirmed): void
    {
        $this->confirmed = $confirmed;
    }

    public function getFPort(): int
    {
        return $this->fPort;
    }

    public function setFPort(int $fPort): void
    {
        $this->fPort = $fPort;
    }

    public function getQueueItemId(): ?string
    {
        return $this->queueItemId;
    }

    public function setQueueItemId(string $queueItemId): void
    {
        $this->queueItemId = $queueItemId;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): void
    {
        $this->error = $error;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
