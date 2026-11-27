<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Removes the per-file embedding pilot (ai-embed-bundle + DuckDB). README vectors stay in
 * package.embedding. Recreating the table means reverting the pilot code as well.
 */
final class Version20261127120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop package_file_embedding (per-file embedding pilot removed)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS package_file_embedding');
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The pilot table and its vectors are gone; restore the pilot code and re-embed instead.');
    }
}
