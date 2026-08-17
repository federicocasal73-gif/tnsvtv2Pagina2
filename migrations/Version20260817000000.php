<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F8 — Calendario Académico + Clases 1:1.
 * Adds 3 tables: calendar_events, mentor_availability, class_bookings.
 *
 * NOTE: No DB-level FKs to users (cross-table FKs require matching
 * engine+charset+collation which the existing users table may not match).
 * Relational integrity is enforced at the application layer via
 * Doctrine entities + getCurrentUser authorization guards.
 */
final class Version20260817000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F8: Calendar académico + bookings 1:1 (3 tablas)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('calendar_events')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE calendar_events (
                    id BIGINT AUTO_INCREMENT NOT NULL,
                    owner_id BIGINT NOT NULL,
                    title VARCHAR(200) NOT NULL,
                    description LONGTEXT DEFAULT NULL,
                    type VARCHAR(32) NOT NULL,
                    starts_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    ends_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                    mentor_id BIGINT DEFAULT NULL,
                    location VARCHAR(200) DEFAULT NULL,
                    meeting_url VARCHAR(500) DEFAULT NULL,
                    status VARCHAR(32) NOT NULL,
                    color VARCHAR(16) DEFAULT NULL,
                    max_attendees INT NOT NULL DEFAULT 0,
                    current_attendees INT NOT NULL DEFAULT 0,
                    recurring TINYINT(1) NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    INDEX idx_ce_owner (owner_id),
                    INDEX idx_ce_mentor (mentor_id),
                    INDEX idx_ce_starts_at (starts_at),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        }

        if (!$schema->hasTable('mentor_availability')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE mentor_availability (
                    id BIGINT AUTO_INCREMENT NOT NULL,
                    mentor_id BIGINT NOT NULL,
                    day_of_week SMALLINT NOT NULL,
                    start_time TIME NOT NULL COMMENT '(DC2Type:time_immutable)',
                    end_time TIME NOT NULL COMMENT '(DC2Type:time_immutable)',
                    status VARCHAR(32) NOT NULL,
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    INDEX idx_ma_mentor_day (mentor_id, day_of_week),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        }

        if (!$schema->hasTable('class_bookings')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE class_bookings (
                    id BIGINT AUTO_INCREMENT NOT NULL,
                    student_id BIGINT NOT NULL,
                    mentor_id BIGINT NOT NULL,
                    event_id BIGINT DEFAULT NULL,
                    start_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    duration_minutes SMALLINT NOT NULL DEFAULT 30,
                    topic VARCHAR(200) NOT NULL,
                    notes LONGTEXT DEFAULT NULL,
                    status VARCHAR(32) NOT NULL,
                    proposed_times JSON DEFAULT NULL,
                    meeting_url VARCHAR(500) DEFAULT NULL,
                    created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                    updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                    INDEX idx_cb_student (student_id),
                    INDEX idx_cb_mentor (mentor_id),
                    INDEX idx_cb_status (status),
                    INDEX idx_cb_start (start_at),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS class_bookings');
        $this->addSql('DROP TABLE IF EXISTS mentor_availability');
        $this->addSql('DROP TABLE IF EXISTS calendar_events');
    }
}
