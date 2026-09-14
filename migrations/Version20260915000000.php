<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the notifications table required by Notification entity +
 * GET /notifications/unread-count and /api/notifications/*.
 *
 * The entity and repositories already existed but no migration ever
 * created the table, so production (MySQL via git pull, no schema:create)
 * throws 42S02 while tests on sqlite pass via schema:create.
 */
final class Version20260915000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create notifications table (user FK, is_read, created_at + indexes)';
    }

    public function up(Schema $schema): void
    {
        if ($schema->hasTable('notifications')) {
            return;
        }

        $isMysql = str_contains($this->connection->getDatabasePlatform()::class, 'MySQL');

        $t = $schema->createTable('notifications');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('user_id', 'integer', ['notnull' => true]);
        $t->addColumn('type', 'string', ['length' => 50, 'notnull' => true]);
        $t->addColumn('content', 'text', ['notnull' => true]);
        $t->addColumn('link', 'string', ['length' => 500, 'notnull' => false]);
        $t->addColumn('is_read', 'boolean', ['default' => false, 'notnull' => true]);
        $t->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['user_id'], 'idx_notif_user');
        $t->addIndex(['user_id', 'is_read'], 'idx_notif_unread');
        if ($isMysql) {
            $t->addForeignKeyConstraint('users', ['user_id'], ['id'], [
                'onDelete' => 'CASCADE',
                'onUpdate' => 'RESTRICT',
            ], 'fk_notif_user');
        } else {
            $t->addForeignKeyConstraint('users', ['user_id'], ['id'], [], 'fk_notif_user');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('notifications')) {
            $schema->dropTable('notifications');
        }
    }
}
