<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260811100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create modbus_register_config table for the Modbus TCP poller (Task 12).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE modbus_register_config (id UUID NOT NULL, device_id UUID NOT NULL, name VARCHAR(255) NOT NULL, address INT NOT NULL, datatype VARCHAR(16) NOT NULL, byteorder VARCHAR(16) NOT NULL, scale DOUBLE PRECISION NOT NULL, interval_secs INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_modbus_register_config_device ON modbus_register_config (device_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_modbus_register_config_device_name ON modbus_register_config (device_id, name)');
        $this->addSql('ALTER TABLE modbus_register_config ADD CONSTRAINT fk_modbus_register_config_device FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE modbus_register_config DROP CONSTRAINT fk_modbus_register_config_device');
        $this->addSql('DROP TABLE modbus_register_config');
    }
}
