<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261108160000 extends AbstractMigration
{
    public function getDescription(): string { return 'Persist reusable song vectors and their content/model fingerprints in PostgreSQL'; }
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE song ADD embedding JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE song ADD embedding_fingerprint VARCHAR(64) DEFAULT NULL');
    }
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE song DROP embedding, DROP embedding_fingerprint');
    }
}
