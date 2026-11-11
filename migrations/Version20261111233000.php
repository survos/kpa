<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261111233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add storage journal metadata and command caller for current bundles; preserve legacy index data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE IF NOT EXISTS storage_journal_head (id INT NOT NULL, sequence BIGINT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE IF NOT EXISTS storage_change (id BIGINT NOT NULL, zone VARCHAR(255) NOT NULL, path TEXT NOT NULL, operation VARCHAR(32) NOT NULL, revision JSON NOT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, processed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IF NOT EXISTS storage_change_pending ON storage_change (processed_at, id)');
        $this->addSql('ALTER TABLE storage_node ADD COLUMN IF NOT EXISTS etag VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE storage_node ADD COLUMN IF NOT EXISTS storage_class VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE storage_node ALTER file_size TYPE BIGINT');
        $this->addSql('ALTER TABLE command_process ADD COLUMN IF NOT EXISTS caller VARCHAR(180) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Storage journal data must be retained during rollback.');
    }
}
