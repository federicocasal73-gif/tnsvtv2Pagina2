<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TNSVT Sprint C.5 — Cuestionarios Macro.
 * Crea la tabla macro_questionnaires para guardar las respuestas
 * de los cuestionarios de perfil de riesgo y conocimiento del mercado.
 */
final class Version20260821001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint C: Crear tabla macro_questionnaires';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('macro_questionnaires')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE macro_questionnaires (
                    id BIGINT AUTO_INCREMENT NOT NULL,
                    user_id BIGINT NOT NULL,
                    questionnaire_type VARCHAR(32) NOT NULL,
                    answers JSON NOT NULL,
                    score INT NOT NULL DEFAULT 0,
                    tier VARCHAR(32) NOT NULL DEFAULT 'moderate',
                    completed_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                    updated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                    UNIQUE KEY uniq_user_type (user_id, questionnaire_type),
                    INDEX idx_user (user_id),
                    INDEX idx_type (questionnaire_type),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('macro_questionnaires')) {
            $this->addSql('DROP TABLE macro_questionnaires');
        }
    }
}
