<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds tasks.scope ('personal' default | 'global') for the hybrid tasks
 * section: personal mentor-assigned tasks + global daily tips for everyone.
 *
 * Existing rows default to 'personal', preserving current behaviour.
 */
final class Version20260916000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add tasks.scope column (personal|global) default personal';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('tasks')) {
            return;
        }
        $table = $schema->getTable('tasks');
        if ($table->hasColumn('scope')) {
            return;
        }

        $table->addColumn('scope', 'string', [
            'length' => 16,
            'notnull' => true,
            'default' => 'personal',
        ]);
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('tasks')) {
            return;
        }
        $table = $schema->getTable('tasks');
        if ($table->hasColumn('scope')) {
            $table->dropColumn('scope');
        }
    }
}
