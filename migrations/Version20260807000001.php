<?php

namespace Doctrine\Migrations\Version20260807000001;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create `settings` table for Phase 1c (Sanctum settings).
 */
final class CreateSettingsTable extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create settings table for Phase 1c configuration (feature flags, tier prices)';
    }

    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('settings')) {
            $this->addSql('CREATE TABLE settings (
                `key` VARCHAR(100) NOT NULL,
                value LONGTEXT DEFAULT NULL,
                category VARCHAR(50) DEFAULT NULL,
                description VARCHAR(200) DEFAULT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY(`key`),
                INDEX idx_settings_category (category)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        }

        // Seed defaults
        $defaults = [
            // Tier prices (monthly USD)
            ['tier.price.initiate', '0', 'tier', 'Price for INITIATE tier (free)'],
            ['tier.price.aspirant', '9.99', 'tier', 'Price for ASPIRANT tier'],
            ['tier.price.tier_1', '29.99', 'tier', 'Price for TIER_1'],
            ['tier.price.tier_2', '79.99', 'tier', 'Price for TIER_2'],
            ['tier.price.tier_3_zenith', '199.99', 'tier', 'Price for TIER_3 ZENITH'],
            ['tier.price.master', '499.99', 'tier', 'Price for MASTER tier'],

            // Feature flags
            ['feature.macro.no_trade_window', '1', 'feature', 'Enable 15-min no-trade window around high-impact events'],
            ['feature.oracle.enabled', '1', 'feature', 'Enable Oráculo de Métricas'],
            ['feature.audio_hub.enabled', '1', 'feature', 'Enable Audio Hub (Santuario de Frecuencias)'],
            ['feature.frequencies.custom_upload', '1', 'feature', 'Allow users to upload custom frequency files'],
            ['feature.signup.email_verification', '0', 'feature', 'Require email verification on signup'],

            // General
            ['site.name', 'T.N.S.V.T', 'general', 'Site display name'],
            ['site.tagline', 'Neuro-Spiritual Value Theory', 'general', 'Site tagline / motto'],
            ['support.email', 'support@tnsvt.app', 'general', 'Support contact email'],

            // Limits
            ['limit.max_devices_per_user', '5', 'limit', 'Max simultaneous devices per user'],
            ['limit.max_frequencies_per_user', '50', 'limit', 'Max custom frequencies per user'],
            ['limit.max_journal_entries_per_day', '20', 'limit', 'Max journal entries per user per day'],
        ];

        foreach ($defaults as [$key, $value, $category, $description]) {
            $this->addSql(
                "INSERT IGNORE INTO settings (`key`, value, category, description, updated_at) VALUES (?, ?, ?, ?, NOW())",
                [$key, $value, $category, $description]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS settings');
    }
}