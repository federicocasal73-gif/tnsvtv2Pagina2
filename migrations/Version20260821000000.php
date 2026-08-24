<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TNSVT Sprint 2: Adds theme_preference column to users table
 * for the dark/light/auto theme system.
 */
final class Version20260821000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sprint 2: Add theme_preference column to users table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('users');
        if (!$table->hasColumn('theme_preference')) {
            $this->addSql('ALTER TABLE users ADD theme_preference VARCHAR(16) DEFAULT \'auto\' NOT NULL');
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('users');
        if ($table->hasColumn('theme_preference')) {
            $this->addSql('ALTER TABLE users DROP theme_preference');
        }
    }
}
