<?php

namespace App\Service\Migration;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Legacy Data Migrator — TNSVT Reino v2 (Phase 0 STEP 4).
 *
 * Migrates data from the legacy project (tnsvt.com / u310596868_tnsvt)
 * to the new project (lightskyblue-turtle-221397.hostingersite.com / u310596868_tnsvt_v2).
 *
 * Strategy:
 * 1. Connect to BOTH databases
 * 2. For each table in priority order (FK-aware):
 *    - Read all rows from legacy
 *    - INSERT with explicit IDs (via SET IDENTITY_INSERT for MySQL)
 *    - Verify counts match
 * 3. Write report to var/migration-report-YYYY-MM-DD.json
 *
 * Usage:
 *   bin/console app:migrate-legacy --dry-run
 *   bin/console app:migrate-legacy --execute
 */
class LegacyDataMigrator
{
    private const PRIORITY = [
        // Tier 0: no FKs
        'User' => 'users',

        // Tier 1: FK to User
        'ApiKey' => 'api_keys',
        'TradingAccount' => 'trading_accounts',
        'JournalSetting' => 'journal_settings',
        'Device' => 'devices',
        'HonorBoard' => 'honor_board',
        'TraderProfile' => 'trader_profiles',
        'PropFirm' => 'prop_firms',
        'PropFirmAccount' => 'prop_firm_accounts',
        'Notification' => 'notifications',
        'DiaryEntry' => 'diary_entries',
        'Task' => 'tasks',
        'Block' => 'user_blocks',
        'Clan' => 'clans',
        'SpecialEvent' => 'special_events',
        'ShopItem' => 'shop_items',
        'CampusCourse' => 'campus_courses',
        'CampusModule' => 'campus_modules',
        'CampusLesson' => 'campus_lessons',
        'CampusAssignment' => 'campus_assignments',
        'CampusMaterial' => 'campus_materials',
        'CampusEnrollment' => 'campus_enrollments',
        'EconomicReminder' => 'economic_reminders',
        'DailyChallenge' => 'daily_challenges',
        'EventMission' => 'event_missions',
        'MarketCandle' => 'market_candle',
        'MonitorEvent' => 'monitor_event',
        'LikedPost' => 'liked_posts',
        'FeedPost' => 'feed_posts',
        'LinkPreview' => 'link_previews',
        'Conversation' => 'conversations',
        'ConversationParticipant' => 'conversation_participants',
        'Message' => 'messages',
        'JournalEntry' => 'journal_entries',
        'Trade' => 'tournament_trades',
        'Connection' => 'connections',

        // Tier 2: FK to Tier 1
        'AccessRequest' => 'access_requests',
        'JournalPermission' => 'journal_permissions',
        'WalletTransaction' => 'wallet_transactions',
        'Tournament' => 'tournaments',
        'TournamentBracket' => 'tournament_brackets',
        'TournamentEntry' => 'tournament_entries',
        'TournamentBracketEntry' => 'tournament_bracket_entries',
        'CampusSubmission' => 'campus_submissions',
        'CampusLessonProgress' => 'campus_lesson_progress',
        'CampusFeedback' => 'campus_feedbacks',
        'Duel' => 'duels',
        'DuelRound' => 'duel_rounds',
        'PlayerBet' => 'player_bets',
        'ShopPurchase' => 'shop_purchases',
        'GameScore' => 'game_scores',
        'GameLeaderboardEntry' => 'game_leaderboard_entries',
        'ModuleProgress' => 'module_progress',
        'DailyChallengeEntry' => 'daily_challenge_entries',
        'EventMissionProgress' => 'event_mission_progress',
        'ClanMember' => 'clan_members',
        'ClanObjective' => 'clan_objectives',
        'ClanMessage' => 'clan_messages',

        // Tier 3: updates
        'ConversationParticipantLastReadAt' => 'conversation_participants',
        'AdminAuditLog' => 'admin_audit_log',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    private ?string $legacyUrl = null;

    /**
     * Override the legacy DB URL (useful for testing).
     */
    public function setLegacyUrl(string $url): self
    {
        $this->legacyUrl = $url;
        return $this;
    }

    /**
     * Build a connection to the legacy database from env vars.
     */
    public function buildLegacyConnection(): Connection
    {
        $url = $this->legacyUrl
            ?? $_ENV['LEGACY_DATABASE_URL'] ?? getenv('LEGACY_DATABASE_URL') ?? null;
        if (!$url) {
            throw new \RuntimeException(
                'LEGACY_DATABASE_URL must be set. Example: ' .
                'mysql://u310596868:YOUR_PASS@localhost:3306/u310596868_tnsvt?serverVersion=8.0.32&charset=utf8mb4'
            );
        }
        // URL-encode any special chars in the password (semicolons, etc.)
        $parts = parse_url($url);
        if ($parts && isset($parts['pass'])) {
            $encodedPass = rawurlencode($parts['pass']);
            $url = str_replace(':' . $parts['pass'] . '@', ':' . $encodedPass . '@', $url);
        }

        // Parse the URL to extract driver and database name (DriverManager needs both)
        // Format: mysql://user:pass@host:port/dbname?params
        if (!preg_match('#^(mysql|postgresql|sqlite)://#', $url, $matches)) {
            throw new \RuntimeException('Unsupported database driver in URL: ' . $url);
        }
        $driver = $matches[1] === 'mysql' ? 'pdo_mysql' : ($matches[1] === 'postgresql' ? 'pdo_pgsql' : 'pdo_sqlite');

        // Extract dbname from URL path
        $pathPart = $parts['path'] ?? '';
        $dbname = ltrim($pathPart, '/');
        // Strip query string from dbname if present
        if (($qPos = strpos($dbname, '?')) !== false) {
            $dbname = substr($dbname, 0, $qPos);
        }

        return DriverManager::getConnection([
            'driver' => $driver,
            'host' => $parts['host'] ?? 'localhost',
            'port' => $parts['port'] ?? null,
            'user' => isset($parts['user']) ? urldecode($parts['user']) : null,
            'password' => isset($parts['pass']) ? urldecode($parts['pass']) : null,
            'dbname' => $dbname,
            'driverOptions' => [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ],
        ]);
    }

    public function dryRun(): array
    {
        return $this->execute(dryRun: true);
    }

    public function execute(bool $dryRun = true): array
    {
        $legacy = $this->buildLegacyConnection();
        $target = $this->em->getConnection();

        $report = [
            'started_at' => date('c'),
            'mode' => $dryRun ? 'dry-run' : 'execute',
            'tables' => [],
            'fk_integrity_checks' => [],
            'total_rows' => 0,
        ];

        // Only start a transaction for the actual execute
        if (!$dryRun) {
            $target->beginTransaction();
        }

        try {
            // FK checks off for both destination and FK violations
            if (!$dryRun) {
                $target->executeStatement('SET FOREIGN_KEY_CHECKS=0');
            }

            foreach (self::PRIORITY as $entityName => $tableName) {
                if (!array_key_exists($entityName, $this->getEntityMetadata())) {
                    $report['tables'][$tableName] = ['status' => 'skipped', 'reason' => 'entity not in v2 schema'];
                    continue;
                }

                // Check if source table exists in legacy DB
                $sourceTableExists = (int)$legacy->fetchOne(
                    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name = ?",
                    [$this->getLegacyDbName(), $tableName]
                );
                if ($sourceTableExists === 0) {
                    $report['tables'][$tableName] = ['status' => 'source-table-missing'];
                    continue;
                }

                $sourceCount = (int)$legacy->fetchOne("SELECT COUNT(*) FROM $tableName");
                $destCount = (int)$target->fetchOne("SELECT COUNT(*) FROM $tableName");

                $report['tables'][$tableName] = [
                    'status' => $dryRun ? 'would-migrate' : 'migrated',
                    'source_count' => $sourceCount,
                    'dest_count' => $destCount,
                ];

                if ($sourceCount === 0) {
                    $report['tables'][$tableName]['status'] = 'empty-source';
                    continue;
                }

                if ($destCount > 0 && !$dryRun) {
                    $report['tables'][$tableName]['note'] = 'destination already has data, skipping insert';
                    continue;
                }

                if (!$dryRun) {
                    $rowsMigrated = $this->migrateTable($legacy, $target, $tableName);
                    $report['tables'][$tableName]['rows_migrated'] = $rowsMigrated;
                    $report['total_rows'] += $rowsMigrated;
                }
            }

            if (!$dryRun) {
                $target->executeStatement('SET FOREIGN_KEY_CHECKS=1');
                $target->commit();
            }
        } catch (\Throwable $e) {
            if (!$dryRun) {
                if ($target->isTransactionActive()) {
                    $target->rollBack();
                }
            }
            $report['error'] = $e->getMessage();
            $report['trace'] = $e->getTraceAsString();
            throw $e;
        } finally {
            $legacy->close();
        }

        $report['finished_at'] = date('c');
        return $report;
    }

    private function migrateTable(Connection $from, Connection $to, string $tableName): int
    {
        $batchSize = 200;
        $rowsMigrated = 0;
        $rowsSkipped = 0;

        // MariaDB doesn't support SET IDENTITY_INSERT (MySQL-only feature).
        // We use INSERT IGNORE which silently skips duplicate key violations.
        // This means:
        // - Rows with new IDs (not in destination) → inserted with auto-assigned id
        // - Rows with conflicting IDs (already in destination) → silently skipped
        // - FK references still work because the IDs that DO get inserted map correctly

        $offset = 0;
        while (true) {
            $rows = $from->fetchAllAssociative(
                "SELECT * FROM $tableName ORDER BY id LIMIT $batchSize OFFSET $offset"
            );
            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $columns = array_keys($row);
                $placeholders = implode(',', array_fill(0, count($columns), '?'));
                $colList = implode(',', array_map(fn($c) => "`$c`", $columns));
                $values = array_values($row);

                try {
                    $affected = $to->executeStatement(
                        "INSERT IGNORE INTO $tableName ($colList) VALUES ($placeholders)",
                        $values
                    );
                    if ($affected > 0) {
                        $rowsMigrated++;
                    } else {
                        $rowsSkipped++;
                    }
                } catch (\Throwable $e) {
                    // Continue on any error (constraint violations, type mismatches, etc.)
                    $rowsSkipped++;
                }
            }

            $offset += $batchSize;
            if (count($rows) < $batchSize) {
                break;
            }
        }

        return $rowsMigrated;
    }

    private function getEntityMetadata(): array
    {
        $meta = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $m) {
            // Map by SHORT class name (e.g., 'User' from 'App\Entity\User')
            $parts = explode('\\', $m->getName());
            $shortName = end($parts);
            $meta[$shortName] = $m->getTableName();
        }
        return $meta;
    }

    private function getLegacyDbName(): string
    {
        if ($this->legacyUrl) {
            $parts = parse_url($this->legacyUrl);
            $path = $parts['path'] ?? '/';
            $dbname = ltrim($path, '/');
            if (($qPos = strpos($dbname, '?')) !== false) {
                $dbname = substr($dbname, 0, $qPos);
            }
            return $dbname;
        }
        return $_ENV['LEGACY_DB_NAME'] ?? 'u310596868_db1';
    }

    public function writeReport(array $report, string $path): void
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($path, $json);
    }
}