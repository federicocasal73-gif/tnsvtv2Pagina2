<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TNSVT V2.1 — Drop game and shop subsystems.
 *
 * The 13 tables managed by this migration back the Market Instinct mobile-game
 * community features (tournaments, duels, brackets, leaderboards, honor board,
 * shop, daily challenges, special events, missions) plus the in-app shop.
 *
 * The web Sanctum does not surface these features anymore (see sidebar cleanup
 * in templates/shell.html.twig and related commits). To keep the schema aligned
 * with the active product, drop the tables and their FK references.
 *
 * Backup taken before this migration is safe to restore:
 *   ssh u310596868@185.173.111.201
 *   mysqldump -h localhost -u u310596868_tnsvt_v2 --single-transaction \
 *     u310596868_tnsvt_v2 \
 *     tournament_trades tournament_entries tournament_bracket_entries \
 *     tournament_brackets tournaments duels duel_rounds bracket_matches \
 *     game_scores game_leaderboard_entries honor_board shop_purchases shop_items \
 *     > /home/u310596868/backups/tnsvt_game_shop_pre_drop_20260824.sql
 *
 * The down() recreates the original schema so this migration is rollback-able.
 */
final class Version20260822000000 extends AbstractMigration
{
    private const TABLES = [
        // child tables first to satisfy FK constraints on drop order
        'tournament_trades',
        'tournament_entries',
        'tournament_bracket_entries',
        'bracket_matches',
        'duel_rounds',
        'game_leaderboard_entries',
        'game_scores',
        'honor_board',
        'shop_purchases',
        // parent tables
        'tournament_brackets',
        'tournaments',
        'duels',
        'shop_items',
    ];

    public function getDescription(): string
    {
        return 'TNSVT V2.1: drop game and shop tables (Market Instinct + Shop subsystems)';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            if ($schema->hasTable($table)) {
                $this->addSql('DROP TABLE IF EXISTS `' . $table . '`');
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Recreate schema exactly as it was before the drop.
        // Order respects FK dependencies (parent first, child second).

        // tournaments (parent)
        if (!$schema->hasTable('tournaments')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE `tournaments` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `name` varchar(100) NOT NULL,
                  `description` longtext DEFAULT NULL,
                  `entry_fee` decimal(10,2) NOT NULL,
                  `prize_pool` decimal(12,2) NOT NULL,
                  `prize_distribution` varchar(20) NOT NULL,
                  `start_date` datetime NOT NULL,
                  `end_date` datetime NOT NULL,
                  `status` varchar(20) NOT NULL,
                  `max_players` int(11) NOT NULL,
                  `min_players` int(11) NOT NULL,
                  `created_at` datetime NOT NULL,
                  `finished_at` datetime DEFAULT NULL,
                  `created_by_id` int(11) NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `IDX_E4BCFAC3B03A8386` (`created_by_id`),
                  KEY `idx_trn_status` (`status`),
                  KEY `idx_trn_end_date` (`end_date`),
                  KEY `idx_trn_active` (`status`,`end_date`),
                  CONSTRAINT `FK_E4BCFAC3B03A8386` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);
        }

        // tournament_entries (child of tournaments, parent of tournament_trades)
        if (!$schema->hasTable('tournament_entries')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE `tournament_entries` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `starting_equity` decimal(14,4) NOT NULL,
                  `final_equity` decimal(14,4) DEFAULT NULL,
                  `pnl_usd` decimal(14,4) DEFAULT NULL,
                  `pnl_pct` decimal(10,6) DEFAULT NULL,
                  `final_rank` int(11) DEFAULT NULL,
                  `payout_amount` decimal(12,2) DEFAULT NULL,
                  `status` varchar(20) NOT NULL,
                  `joined_at` datetime NOT NULL,
                  `finalized_at` datetime DEFAULT NULL,
                  `tournament_id` int(11) NOT NULL,
                  `user_id` int(11) NOT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uniq_te_tournament_user` (`tournament_id`,`user_id`),
                  KEY `idx_te_tournament` (`tournament_id`),
                  KEY `idx_te_user` (`user_id`),
                  KEY `idx_te_status` (`status`),
                  KEY `idx_te_pnl` (`tournament_id`,`pnl_pct`),
                  CONSTRAINT `FK_46F748CF33D1A3E7` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `FK_46F748CFA76ED395` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);
        }

        // tournament_trades (child of tournament_entries, tournaments)
        if (!$schema->hasTable('tournament_trades')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE `tournament_trades` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `symbol` varchar(16) NOT NULL,
                  `direction` varchar(8) NOT NULL,
                  `timeframe` varchar(4) NOT NULL,
                  `entry_price` decimal(14,4) NOT NULL,
                  `exit_price` decimal(14,4) DEFAULT NULL,
                  `pnl_usd` decimal(14,4) DEFAULT NULL,
                  `pnl_pct` decimal(10,6) DEFAULT NULL,
                  `size_pct` decimal(6,2) NOT NULL,
                  `leverage` decimal(6,2) NOT NULL,
                  `status` varchar(16) NOT NULL,
                  `price_source` varchar(16) NOT NULL,
                  `created_at` datetime NOT NULL,
                  `resolved_at` datetime DEFAULT NULL,
                  `notes` longtext DEFAULT NULL,
                  `entry_id` int(11) NOT NULL,
                  `user_id` int(11) NOT NULL,
                  `tournament_id` int(11) NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `idx_tr_entry` (`entry_id`),
                  KEY `idx_tr_user` (`user_id`),
                  KEY `idx_tr_tournament` (`tournament_id`),
                  KEY `idx_tr_created` (`created_at`),
                  KEY `idx_tr_status` (`status`),
                  CONSTRAINT `FK_DB0BEF7533D1A3E7` FOREIGN KEY (`tournament_id`) REFERENCES `tournaments` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `FK_DB0BEF75A76ED395` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `FK_DB0BEF75BA364942` FOREIGN KEY (`entry_id`) REFERENCES `tournament_entries` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);
        }

        // tournament_brackets (parent of bracket_matches + tournament_bracket_entries)
        if (!$schema->hasTable('tournament_brackets')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE `tournament_brackets` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `name` varchar(100) NOT NULL,
                  `mode` varchar(20) NOT NULL,
                  `max_players` int(11) NOT NULL,
                  `current_round` int(11) NOT NULL,
                  `total_rounds` int(11) NOT NULL,
                  `entry_fee` decimal(10,2) NOT NULL,
                  `prize_pool` decimal(12,2) NOT NULL,
                  `status` varchar(20) NOT NULL,
                  `start_date` datetime NOT NULL,
                  `end_date` datetime NOT NULL,
                  `match_duration_minutes` int(11) NOT NULL,
                  `created_at` datetime NOT NULL,
                  `created_by_id` int(11) NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `IDX_51FB6B97B03A8386` (`created_by_id`),
                  CONSTRAINT `FK_51FB6B97B03A8386` FOREIGN KEY (`created_by_id`) REFERENCES `users` (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);
        }

        // tournament_bracket_entries (child of tournament_brackets)
        if (!$schema->hasTable('tournament_bracket_entries')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE `tournament_bracket_entries` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `joined_at` datetime NOT NULL,
                  `final_rank` int(11) DEFAULT NULL,
                  `prize_won` decimal(12,2) DEFAULT NULL,
                  `eliminated` tinyint(4) NOT NULL,
                  `eliminated_round` int(11) DEFAULT NULL,
                  `tournament_id` int(11) NOT NULL,
                  `user_id` int(11) NOT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `uniq_tbe_tournament_user` (`tournament_id`,`user_id`),
                  KEY `IDX_87287A7C33D1A3E7` (`tournament_id`),
                  KEY `IDX_87287A7CA76ED395` (`user_id`),
                  CONSTRAINT `FK_87287A7C33D1A3E7` FOREIGN KEY (`tournament_id`) REFERENCES `tournament_brackets` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `FK_87287A7CA76ED395` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);
        }

        // bracket_matches (child of tournament_brackets)
        if (!$schema->hasTable('bracket_matches')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE `bracket_matches` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `round` int(11) NOT NULL,
                  `match_index` int(11) NOT NULL,
                  `player1_score` int(11) DEFAULT NULL,
                  `player2_score` int(11) DEFAULT NULL,
                  `status` varchar(20) NOT NULL,
                  `started_at` datetime DEFAULT NULL,
                  `deadline` datetime DEFAULT NULL,
                  `finished_at` datetime DEFAULT NULL,
                  `round_results` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`round_results`)),
                  `created_at` datetime NOT NULL,
                  `tournament_id` int(11) NOT NULL,
                  `player1_id` int(11) DEFAULT NULL,
                  `player2_id` int(11) DEFAULT NULL,
                  `winner_id` int(11) DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `IDX_44FB5A6133D1A3E7` (`tournament_id`),
                  KEY `IDX_44FB5A61C0990423` (`player1_id`),
                  KEY `IDX_44FB5A61D22CABCD` (`player2_id`),
                  KEY `IDX_44FB5A615DFCD4B8` (`winner_id`),
                  KEY `idx_bm_tournament_round` (`tournament_id`,`round`),
                  CONSTRAINT `FK_44FB5A6133D1A3E7` FOREIGN KEY (`tournament_id`) REFERENCES `tournament_brackets` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `FK_44FB5A615DFCD4B8` FOREIGN KEY (`winner_id`) REFERENCES `users` (`id`),
                  CONSTRAINT `FK_44FB5A61C0990423` FOREIGN KEY (`player1_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                  CONSTRAINT `FK_44FB5A61D22CABCD` FOREIGN KEY (`player2_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);
        }

        // duels (parent of duel_rounds)
        if (!$schema->hasTable('duels')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE `duels` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `code` varchar(20) NOT NULL,
                  `entry_fee` decimal(12,2) NOT NULL,
                  `prize_pool` decimal(12,2) NOT NULL,
                  `total_rounds` int(11) NOT NULL,
                  `current_round` int(11) NOT NULL,
                  `player1_pnl` decimal(14,4) NOT NULL,
                  `player2_pnl` decimal(14,4) NOT NULL,
                  `starting_price` decimal(14,4) DEFAULT NULL,
                  `status` varchar(20) NOT NULL,
                  `created_at` datetime NOT NULL,
                  `started_at` datetime DEFAULT NULL,
                  `finished_at` datetime DEFAULT NULL,
                  `player1_id` int(11) NOT NULL,
                  `player2_id` int(11) DEFAULT NULL,
                  `winner_id` int(11) DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `UNIQ_B8297BD877153098` (`code`),
                  KEY `IDX_B8297BD8C0990423` (`player1_id`),
                  KEY `IDX_B8297BD8D22CABCD` (`player2_id`),
                  KEY `IDX_B8297BD85DFCD4B8` (`winner_id`),
                  KEY `idx_duel_code` (`code`),
                  KEY `idx_duel_status` (`status`),
                  CONSTRAINT `FK_B8297BD85DFCD4B8` FOREIGN KEY (`winner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
                  CONSTRAINT `FK_B8297BD8C0990423` FOREIGN KEY (`player1_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
                  CONSTRAINT `FK_B8297BD8D22CABCD` FOREIGN KEY (`player2_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);
        }

        // duel_rounds (child of duels)
        if (!$schema->hasTable('duel_rounds')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE `duel_rounds` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `round_number` int(11) NOT NULL,
                  `player1_move` varchar(10) DEFAULT NULL,
                  `player2_move` varchar(10) DEFAULT NULL,
                  `open_price` decimal(14,4) NOT NULL,
                  `close_price` decimal(14,4) NOT NULL,
                  `high_price` decimal(14,4) NOT NULL,
                  `low_price` decimal(14,4) NOT NULL,
                  `player1_pnl` decimal(14,4) NOT NULL,
                  `player2_pnl` decimal(14,4) NOT NULL,
                  `created_at` datetime NOT NULL,
                  `duel_id` int(11) NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `idx_dr_duel` (`duel_id`),
                  CONSTRAINT `FK_547A863C58875E` FOREIGN KEY (`duel_id`) REFERENCES `duels` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);
        }

        // game_scores
        if (!$schema->hasTable('game_scores')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE `game_scores` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `mode` varchar(32) NOT NULL,
                  `score` int(11) NOT NULL,
                  `xp_gained` int(11) NOT NULL DEFAULT 0,
                  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`metadata`)),
                  `created_at` datetime NOT NULL,
                  `user_id` int(11) NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `IDX_7A659A96A76ED395` (`user_id`),
                  KEY `idx_game_user_mode` (`user_id`,`mode`),
                  KEY `idx_game_score` (`score`),
                  KEY `idx_game_created` (`created_at`),
                  CONSTRAINT `FK_7A659A96A76ED395` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);
        }

        // game_leaderboard_entries
        if (!$schema->hasTable('game_leaderboard_entries')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE `game_leaderboard_entries` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `leaderboard_type` varchar(20) NOT NULL,
                  `period` varchar(20) NOT NULL,
                  `score` int(11) NOT NULL,
                  `season_id` varchar(50) DEFAULT NULL,
                  `created_at` datetime NOT NULL,
                  `updated_at` datetime NOT NULL,
                  `user_id` int(11) NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `IDX_A052DD57A76ED395` (`user_id`),
                  KEY `idx_lb_user_type_period` (`user_id`,`leaderboard_type`,`period`),
                  KEY `idx_lb_score_type_period` (`score`,`leaderboard_type`,`period`),
                  CONSTRAINT `FK_A052DD57A76ED395` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);
        }

        // honor_board
        if (!$schema->hasTable('honor_board')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE `honor_board` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `category` varchar(30) NOT NULL,
                  `value` int(11) NOT NULL,
                  `period` varchar(50) NOT NULL,
                  `season` varchar(20) NOT NULL,
                  `rank` varchar(10) NOT NULL,
                  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`metadata`)),
                  `updated_at` datetime NOT NULL,
                  `created_at` datetime NOT NULL,
                  `user_id` int(11) NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `IDX_244DD637A76ED395` (`user_id`),
                  CONSTRAINT `FK_244DD637A76ED395` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);
        }

        // shop_items
        if (!$schema->hasTable('shop_items')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE `shop_items` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `item_id` varchar(64) NOT NULL,
                  `category` varchar(32) NOT NULL,
                  `name` varchar(128) NOT NULL,
                  `description` varchar(500) DEFAULT NULL,
                  `coin_cost` int(11) NOT NULL DEFAULT 0,
                  `xp_cost` int(11) DEFAULT NULL,
                  `rarity` varchar(16) NOT NULL DEFAULT 'common',
                  `image_url` varchar(255) DEFAULT NULL,
                  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
                  `sort_order` int(11) NOT NULL DEFAULT 0,
                  `active` tinyint(4) NOT NULL DEFAULT 1,
                  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `UNIQ_2608B31F126F525E` (`item_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);
        }

        // shop_purchases (child of shop_items + users)
        if (!$schema->hasTable('shop_purchases')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE `shop_purchases` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `item_id` varchar(64) NOT NULL,
                  `coins_spent` int(11) NOT NULL DEFAULT 0,
                  `xp_spent` int(11) DEFAULT NULL,
                  `purchased_at` datetime NOT NULL DEFAULT current_timestamp(),
                  `user_id` int(11) NOT NULL,
                  PRIMARY KEY (`id`),
                  KEY `IDX_B3D3BF75A76ED395` (`user_id`),
                  CONSTRAINT `FK_B3D3BF75A76ED395` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci
            SQL);
        }
    }
}
