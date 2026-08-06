<?php

namespace Doctrine\Migrations\Version20260806000000;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class CreateFrequencyTables extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create frequency_presets, user_frequencies, frequency_sessions tables for Audio Hub (Phase 3)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('frequency_presets')) {
            $this->addSql('CREATE TABLE frequency_presets (
                id INT AUTO_INCREMENT NOT NULL,
                name VARCHAR(100) NOT NULL,
                frequency INT NOT NULL,
                category VARCHAR(100) DEFAULT NULL,
                description TEXT DEFAULT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                benefits JSON NOT NULL,
                PRIMARY KEY(id),
                INDEX idx_freq_active (frequency, active)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('user_frequencies')) {
            $this->addSql('CREATE TABLE user_frequencies (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                name VARCHAR(200) NOT NULL,
                frequency INT NOT NULL,
                type VARCHAR(50) NOT NULL,
                file_path VARCHAR(500) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at DATETIME NOT NULL,
                PRIMARY KEY(id),
                INDEX idx_uf_user (user_id, created_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        if (!$schema->hasTable('frequency_sessions')) {
            $this->addSql('CREATE TABLE frequency_sessions (
                id INT AUTO_INCREMENT NOT NULL,
                user_id INT NOT NULL,
                preset_id INT DEFAULT NULL,
                user_frequency_id INT DEFAULT NULL,
                duration_minutes INT NOT NULL DEFAULT 0,
                started_at DATETIME NOT NULL,
                ended_at DATETIME DEFAULT NULL,
                completed TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY(id),
                INDEX idx_fs_user (user_id, started_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS frequency_sessions');
        $this->addSql('DROP TABLE IF EXISTS user_frequencies');
        $this->addSql('DROP TABLE IF EXISTS frequency_presets');
    }
}