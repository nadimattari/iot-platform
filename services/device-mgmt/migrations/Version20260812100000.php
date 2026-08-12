<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260812100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create commands table for downlink command lifecycle tracking (Task 16).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE commands (id UUID NOT NULL, device_id UUID NOT NULL, type VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, payload JSONB NOT NULL, confirmed BOOLEAN NOT NULL, f_port INT NOT NULL, queue_item_id UUID DEFAULT NULL, error VARCHAR(512) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_commands_device ON commands (device_id)');
        $this->addSql('CREATE INDEX idx_commands_queue_item ON commands (queue_item_id)');
        $this->addSql('ALTER TABLE commands ADD CONSTRAINT fk_commands_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commands DROP CONSTRAINT fk_commands_device');
        $this->addSql('DROP TABLE commands');
    }
}
