<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TNSVT V3 — Multi-account trading system.
 *
 * Creates the missing DB infrastructure for the multi-account feature
 * that already exists in the V2 backend (TradingAccount entity +
 * TradingAccountController API). The frontend was never wired up because
 * the migration to create the underlying tables was never written.
 *
 * Schema:
 *   1. CREATE TABLE trading_accounts (id, user_id, name, account_size,
 *      color, icon, is_active, deleted_at, sort_order, created_at)
 *      + index on (user_id, is_active, deleted_at).
 *   2. ALTER TABLE users ADD max_accounts INT NOT NULL DEFAULT 3.
 *   3. ALTER TABLE journal_entries ADD account_id BIGINT NULL + index.
 *
 * Backfill:
 *   - For every existing user, create a default "Mi Cuenta" $10,000.
 *   - Link any existing journal_entries to that default account.
 *
 * Reversible via down().
 */
final class Version20260902000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Multi-account trading: trading_accounts table + users.max_accounts + journal_entries.account_id + backfill';
    }

    public function up(Schema $schema): void
    {
        $isMysql = str_contains($this->connection->getDatabasePlatform()::class, 'MySQL');

        // ── 1. trading_accounts table ──
        if (!$schema->hasTable('trading_accounts')) {
            $accounts = $schema->createTable('trading_accounts');
            $accounts->addColumn('id', 'integer', ['autoincrement' => true]);
            $accounts->addColumn('user_id', 'integer', ['notnull' => true]);
            $accounts->addColumn('name', 'string', ['length' => 50, 'notnull' => true]);
            $accounts->addColumn('account_size', 'decimal', [
                'precision' => 12,
                'scale' => 2,
                'default' => 10000,
                'notnull' => true,
            ]);
            $accounts->addColumn('color', 'string', ['length' => 20, 'default' => '#d4af37']);
            $accounts->addColumn('icon', 'string', ['length' => 20, 'default' => '💰']);
            $accounts->addColumn('is_active', 'boolean', ['default' => true, 'notnull' => true]);
            $accounts->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);
            $accounts->addColumn('sort_order', 'integer', ['default' => 0, 'notnull' => true]);
            $accounts->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
            $accounts->setPrimaryKey(['id']);
            $accounts->addIndex(['user_id', 'is_active', 'deleted_at'], 'idx_ta_user_active');
            if ($isMysql) {
                $accounts->addForeignKeyConstraint('users', ['user_id'], ['id'], [
                    'onDelete' => 'CASCADE',
                    'onUpdate' => 'RESTRICT',
                ], 'fk_ta_user');
            } else {
                $accounts->addForeignKeyConstraint('users', ['user_id'], ['id'], [], 'fk_ta_user');
            }
        }

        // ── 2. users.max_accounts ──
        if ($schema->hasTable('users') && !$schema->getTable('users')->hasColumn('max_accounts')) {
            $schema->getTable('users')->addColumn('max_accounts', 'integer', [
                'default' => 3,
                'notnull' => true,
            ]);
        }

        // ── 3. journal_entries.account_id ──
        if ($schema->hasTable('journal_entries') && !$schema->getTable('journal_entries')->hasColumn('account_id')) {
            $schema->getTable('journal_entries')->addColumn('account_id', 'bigint', ['notnull' => false]);
            $schema->getTable('journal_entries')->addIndex(['account_id'], 'idx_je_account');
        }

        // ── Backfill via raw SQL (DB-agnostic) ──
        // For each user, create "Mi Cuenta" $10,000 if they have no accounts.
        // Then link any orphan journal_entries to that account.
        $this->addSql(<<<'SQL'
            INSERT INTO trading_accounts (user_id, name, account_size, color, icon, is_active, sort_order, created_at)
            SELECT u.id, 'Mi Cuenta', 10000.00, '#d4af37', '💰', 1, 0, NOW()
            FROM users u
            WHERE u.active = 1
              AND NOT EXISTS (
                SELECT 1 FROM trading_accounts ta WHERE ta.user_id = u.id
              )
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE journal_entries je
            INNER JOIN trading_accounts ta ON ta.user_id = je.user_id AND ta.sort_order = 0
            SET je.account_id = ta.id
            WHERE je.account_id IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Drop FK then columns then table
        if ($schema->hasTable('journal_entries') && $schema->getTable('journal_entries')->hasColumn('account_id')) {
            $schema->getTable('journal_entries')->dropColumn('account_id');
        }
        if ($schema->hasTable('users') && $schema->getTable('users')->hasColumn('max_accounts')) {
            $schema->getTable('users')->dropColumn('max_accounts');
        }
        if ($schema->hasTable('trading_accounts')) {
            $schema->dropTable('trading_accounts');
        }
    }
}
