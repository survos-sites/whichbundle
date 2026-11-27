<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261127105620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Questions and answers from /ask (App\Entity\ChatTurn)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE chat_turn (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, conversation UUID NOT NULL, question TEXT NOT NULL, answer TEXT NOT NULL, tools JSONB NOT NULL, failed BOOLEAN NOT NULL, model VARCHAR(64) DEFAULT NULL, duration_ms INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_86BC88068B8E8428 ON chat_turn (created_at)');
        $this->addSql('CREATE INDEX IDX_86BC88068A8E26E9 ON chat_turn (conversation)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE chat_turn');
    }
}
