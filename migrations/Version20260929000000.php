<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Elimina los módulos Clanes y Frecuencias (2026-09-29).
 *
 * - Clanes: clans, clan_members, clan_messages, clan_objectives
 * - Frecuencias: frequency_presets, user_frequencies, frequency_sessions
 *
 * La futura sección Meditación reusará MusicController (/api/music/*),
 * que NO se toca. Idempotente: solo dropea lo que exista.
 * Down es no-op: restaurar requiere el backup pre-borrado
 * (tag backup-pre-remove-freq-clan-20260929 + dump ~/backups/).
 */
final class Version20260929000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop clan_* and frequency_* tables (remove Clanes + Frecuencias modules, keep Music intact)';
    }

    public function up(Schema $schema): void
    {
        // Idempotente en MySQL/MariaDB, PostgreSQL y SQLite.
        foreach ([
            'clan_messages',
            'clan_members',
            'clan_objectives',
            'clans',
            'frequency_sessions',
            'user_frequencies',
            'frequency_presets',
        ] as $t) {
            $this->addSql(sprintf('DROP TABLE IF EXISTS %s', $t));
        }
    }

    public function down(Schema $schema): void
    {
        // No-op: las tablas se recreaban vía entidades ya eliminadas.
        // Restaurar = re-deploy del tag backup + restore del dump.
    }
}
