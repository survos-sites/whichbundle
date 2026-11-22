<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261122150000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add disposable per-file, per-profile chunk vectors without altering legacy package embeddings'; }
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE package_file_embedding (id VARCHAR(64) PRIMARY KEY, file_id VARCHAR(255) NOT NULL REFERENCES package_file(id) ON DELETE CASCADE, profile_id VARCHAR(64) NOT NULL, request_id VARCHAR(64) NOT NULL, document_id VARCHAR(64) NOT NULL, ordinal INT NOT NULL, char_start INT NOT NULL, char_end INT NOT NULL, heading TEXT NOT NULL, vector JSONB NOT NULL)');
        $this->addSql('CREATE INDEX package_file_embedding_file_idx ON package_file_embedding (file_id)');
    }
    public function down(Schema $schema): void { $this->addSql('DROP TABLE package_file_embedding'); }
}
