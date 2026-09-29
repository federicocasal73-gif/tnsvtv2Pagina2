<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * 2FA por mail + recupero de contraseña (2026-09-29).
 *
 * - Nueva tabla two_factor_challenges (códigos de un solo uso, solo sha256).
 * - users: email_verified_at, two_factor_enabled, two_factor_exempt.
 *
 * Idempotente: salta lo que ya exista (sqlite local via schema:create).
 */
final class Version20260930000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create two_factor_challenges + users.email_verified_at/two_factor_enabled/two_factor_exempt (2FA mail + password recovery)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('two_factor_challenges')) {
            $t = $schema->createTable('two_factor_challenges');
            $t->addColumn('id', 'integer', ['autoincrement' => true]);
            $t->addColumn('user_id', 'integer', ['notnull' => true]);
            $t->addColumn('purpose', 'string', ['length' => 16]);
            $t->addColumn('code_hash', 'string', ['length' => 64]);
            $t->addColumn('expires_at', 'datetime_immutable', ['notnull' => true]);
            $t->addColumn('attempts', 'integer', ['default' => 0]);
            $t->addColumn('consumed_at', 'datetime_immutable', ['notnull' => false]);
            $t->addColumn('last_sent_at', 'datetime_immutable', ['notnull' => false]);
            $t->addColumn('resend_count', 'integer', ['default' => 0]);
            $t->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
            $t->setPrimaryKey(['id']);
            $t->addIndex(['user_id', 'purpose', 'consumed_at'], 'idx_tfc_user_purpose');
            $t->addForeignKeyConstraint('users', ['user_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_tfc_user');
        }

        $users = $schema->getTable('users');
        if (!$users->hasColumn('email_verified_at')) {
            $users->addColumn('email_verified_at', 'datetime_immutable', ['notnull' => false]);
        }
        if (!$users->hasColumn('two_factor_enabled')) {
            $users->addColumn('two_factor_enabled', 'boolean', ['default' => false]);
        }
        if (!$users->hasColumn('two_factor_exempt')) {
            $users->addColumn('two_factor_exempt', 'boolean', ['default' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('two_factor_challenges')) {
            $schema->dropTable('two_factor_challenges');
        }
        if ($schema->hasTable('users')) {
            $users = $schema->getTable('users');
            foreach (['two_factor_exempt', 'two_factor_enabled', 'email_verified_at'] as $col) {
                if ($users->hasColumn($col)) {
                    $users->dropColumn($col);
                }
            }
        }
    }
}
