<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Messenger async transport tables (doctrine transport).
 *
 * Run after the Symfony Messenger bundle is configured with `doctrine://` transport.
 * SQLite and MySQL supported.
 */
final class Version20260816204200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create messenger_messages table for async transport';
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $platformClass = (new \ReflectionClass($platform))->getShortName();

        if ($platformClass === 'SqlitePlatform') {
            $this->addSql(<<<'SQL'
                CREATE TABLE messenger_messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    body TEXT NOT NULL,
                    headers TEXT NOT NULL,
                    queue_name VARCHAR(190) NOT NULL,
                    created_at DATETIME NOT NULL,
                    available_at DATETIME NOT NULL,
                    delivered_at DATETIME DEFAULT NULL
                )
            SQL);
        } else {
            $this->addSql(<<<'SQL'
                CREATE TABLE messenger_messages (
                    id BIGINT AUTO_INCREMENT NOT NULL,
                    body LONGTEXT NOT NULL,
                    headers LONGTEXT NOT NULL,
                    queue_name VARCHAR(190) NOT NULL,
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    delivered_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                    PRIMARY KEY(id)
                )
            SQL);
            $this->addSql('CREATE INDEX IDX_75EA56E0E6D5C6AA ON messenger_messages (queue_name)');
            $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (available_at)');
            $this->addSql('CREATE INDEX IDX_75EA56E0B84B9AA0 ON messenger_messages (delivered_at)');
        }

        // SQLite indexes
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_messenger_queue ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_messenger_available ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_messenger_delivered ON messenger_messages (delivered_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
    }
}