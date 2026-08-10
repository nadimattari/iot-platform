<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260810042957 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE device_groups (id UUID NOT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_device_groups_name ON device_groups (name)');
        $this->addSql('CREATE TABLE device_profiles (id UUID NOT NULL, name VARCHAR(255) NOT NULL, field_defs JSONB NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_device_profiles_name ON device_profiles (name)');
        $this->addSql('CREATE TABLE devices (id UUID NOT NULL, name VARCHAR(255) NOT NULL, protocol VARCHAR(16) NOT NULL, dev_eui VARCHAR(16) DEFAULT NULL, api_key_hash VARCHAR(64) DEFAULT NULL, metadata JSONB NOT NULL, enabled BOOLEAN NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, group_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_11074E9AFE54D947 ON devices (group_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_devices_dev_eui ON devices (dev_eui)');
        $this->addSql('ALTER TABLE devices ADD CONSTRAINT FK_11074E9AFE54D947 FOREIGN KEY (group_id) REFERENCES device_groups (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE devices DROP CONSTRAINT FK_11074E9AFE54D947');
        $this->addSql('DROP TABLE device_groups');
        $this->addSql('DROP TABLE device_profiles');
        $this->addSql('DROP TABLE devices');
    }
}
