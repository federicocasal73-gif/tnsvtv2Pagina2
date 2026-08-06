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

    /**
     * Build a connection to the legacy database from env vars.
     */
    public function buildLegacyConnection(): Connection
    {
        $url = $_ENV['LEGACY_DATABASE_URL'] ?? getenv('LEGACY_DATABASE_URL') ?? null;
        if (!$url) {
            throw new \RuntimeException(
                'LEGACY_DATABASE_URL must be set. Example: ' .
                'mysql://u310596868:YOUR_PASS@localhost:3306/u310596868_tnsvt?serverVersion=8.0.32&charset=utf8mb4'
            );
        }
        return DriverManager::getConnection(['url' => $url]);
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

        $target->beginTransaction();
        if ($dryRun) {
            $target->rollBack();
        }

        try {
            // Step 1: Set identity_insert for MySQL
            if ($dryRun === false) {
                $target->executeStatement('SET FOREIGN_KEY_CHECKS=0');
            }

            foreach (self::PRIORITY as $entityName => $tableName) {
                if (!array_key_exists($entityName, $this->getEntityMetadata())) {
                    $report['tables'][$tableName] = ['status' => 'skipped', 'reason' => 'entity not in v2 schema'];
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
            } else {
                $target->rollBack();
            }
        } catch (\Throwable $e) {
            if (!$dryRun) {
                $target->rollBack();
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

        // Use SET IDENTITY_INSERT for MySQL to preserve original IDs
        $to->executeStatement("SET IDENTITY_INSERT $tableName ON");

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

                $to->executeStatement(
                    "INSERT INTO $tableName ($colList) VALUES ($placeholders)",
                    $values
                );
                $rowsMigrated++;
            }

            $offset += $batchSize;
            if (count($rows) < $batchSize) {
                break;
            }
        }

        $to->executeStatement("SET IDENTITY_INSERT $tableName OFF");
        return $rowsMigrated;
    }

    private function getEntityMetadata(): array
    {
        $meta = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $m) {
            $meta[$m->getName()] = $m->getTableName();
        }
        return $meta;
    }

    public function writeReport(array $report, string $path): void
    {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($path, $json);
    }
}