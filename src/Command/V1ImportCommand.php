<?php

namespace App\Command;

use App\Entity\TradeSnapshot;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Import users + sample data from the V1 SQLite backup into V2.
 *
 * Source DB: C:\Users\HP 240 inch G9\Documents\TNSVT-WORK\BACKUP_tnsvt-symfony_PRE_MIGRACION_2026-07-08\var\data_dev.db
 *
 * Imports:
 *   - 8 active V1 users (skip 3 inactive: MORDELON17, 2617A, AMMY@NAHOMY123)
 *   - ADMIN01 keeps its bcrypt hash (V2 uses same Symfony auto hasher)
 *   - Devices (27 FCM tokens), conversations + messages, notifications
 *   - Trading accounts, access requests, connections, feed posts
 *
 * Skips:
 *   - trades, tournaments, tournament_entries (tables dropped in V2)
 *   - diary_entries (encrypted; unrecoverable without iv+token)
 *
 * Run: php bin/console app:v1-import [--sqlite=/path/to/data_dev.db]
 */
#[AsCommand(
    name: 'app:v1-import',
    description: 'Import users + data from V1 SQLite backup into V2 production database',
)]
class V1ImportCommand extends Command
{
    private const DEFAULT_V1_DB = 'C:\\Users\\HP 240 inch G9\\Documents\\TNSVT-WORK\\BACKUP_tnsvt-symfony_PRE_MIGRACION_2026-07-08\\var\\data_dev.db';

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private Connection $connection,
        private UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('sqlite', null, InputOption::VALUE_REQUIRED, 'Path to V1 SQLite DB', self::DEFAULT_V1_DB);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dbPath = $input->getOption('sqlite');

        if (!is_file($dbPath)) {
            $io->error("V1 SQLite not found at: $dbPath");
            $io->writeln('Tip: place data_dev.db in var/ or pass --sqlite=/path/to/db');
            return Command::FAILURE;
        }

        $io->title('V1 → V2 Import');

        // Read V1 data using PDO directly (SQLite)
        try {
            $v1 = new \PDO('sqlite:' . $dbPath);
            $v1->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        } catch (\PDOException $e) {
            $io->error('Cannot open V1 SQLite: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $stats = ['users' => 0, 'devices' => 0, 'conversations' => 0, 'messages' => 0,
                  'trading_accounts' => 0, 'connections' => 0, 'access_requests' => 0,
                  'feed_posts' => 0, 'notifications' => 0, 'skipped' => 0];

        // ─── 1. USERS ───
        $io->section('1. Importing active users');
        $stmt = $v1->query("SELECT id, code, email, name, active, last_login, roles, password,
                                    wallet_balance, max_accounts, last_activity_at, notification_sound
                             FROM users WHERE active = 1 ORDER BY id");
        $v1Users = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($v1Users as $row) {
            $code = $row['code'];
            $existing = $this->userRepo->findOneBy(['code' => $code]);
            if ($existing) {
                $io->writeln("  ⏭  Skipped (exists): $code");
                $stats['skipped']++;
                continue;
            }

            $user = new User();
            $user->setCode($code);
            $user->setName($row['name'] ?? 'Sin Nombre');
            $user->setEmail($row['email']);
            $user->setActive((bool) $row['active']);
            $user->setRoles(json_decode($row['roles'] ?? '["ROLE_USER"]', true) ?: ['ROLE_USER']);
            $user->setMaxAccounts((int) ($row['max_accounts'] ?? 1));
            $user->setWalletBalance((float) ($row['wallet_balance'] ?? 0));
            $user->setNotificationSound($row['notification_sound'] ?? 'chime');

            try {
                $user->setLastLogin(new \DateTimeImmutable($row['last_login'] ?? 'now'));
            } catch (\Exception) {
                $user->setLastLogin(new \DateTimeImmutable());
            }
            try {
                $user->setLastActivityAt(new \DateTimeImmutable($row['last_activity_at'] ?? 'now'));
            } catch (\Exception) {
                $user->setLastActivityAt(new \DateTimeImmutable());
            }

            // Use V1 password hash directly if present (auto-hasher is identical in V1+V2)
            if (!empty($row['password'])) {
                $reflection = new \ReflectionClass($user);
                $passwordProp = $reflection->getProperty('password');
                $passwordProp->setAccessible(true);
                $passwordProp->setValue($user, $row['password']);
            }

            // Set tier based on role
            $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);
            $user->setTier($isAdmin ? 'MASTER' : 'INITIATE');

            $this->em->persist($user);
            $stats['users']++;
            $io->writeln("  ✓ Imported: $code ({$user->getName()})");
        }

        $this->em->flush();

        // ─── 2. DEVICES (FCM tokens) ───
        $io->section('2. Importing devices');
        $this->importDevices($v1, $io, $stats);

        // ─── 3. CONVERSATIONS + MESSAGES ───
        $io->section('3. Importing conversations + messages');
        $this->importConversations($v1, $io, $stats);

        // ─── 4. TRADING ACCOUNTS ───
        $io->section('4. Importing trading accounts');
        $this->importTradingAccounts($v1, $io, $stats);

        // ─── 5. CONNECTIONS + ACCESS REQUESTS ───
        $io->section('5. Importing connections + access requests');
        $this->importConnections($v1, $io, $stats);

        // ─── 6. FEED POSTS ───
        $io->section('6. Importing feed posts');
        $this->importFeedPosts($v1, $io, $stats);

        // ─── 7. NOTIFICATIONS ───
        $io->section('7. Importing notifications');
        $this->importNotifications($v1, $io, $stats);

        // ─── SUMMARY ───
        $io->success('Import complete.');
        $io->table(['Resource', 'Imported', 'Skipped'], array_map(
            fn($key) => [ucfirst(str_replace('_', ' ', $key)),
                         $stats[$key] ?? 0,
                         $key === 'users' ? $stats['skipped'] : '—'],
            array_keys($stats)
        ));

        $io->section('Next steps');
        $io->listing([
            'Login with ADMIN01 / TNSVT-2026-CristoRey! to verify admin access',
            'Login with DEMO / demo to verify user access',
            'Run app:seed-frequencies and app:seed-multifractal-course if not already done',
            'Check /api/leaderboard, /api/feed, /api/chat/conversations for imported data',
        ]);

        return Command::SUCCESS;
    }

    private function importDevices(\PDO $v1, SymfonyStyle $io, array &$stats): void
    {
        try {
            $rows = $v1->query("SELECT user_id, fcm_token, platform, device_model, registered_at, last_seen_at FROM devices")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $user = $this->userRepo->find($row['user_id']);
                if (!$user) continue;
                $this->connection->executeStatement(
                    'INSERT INTO devices (user_id, fcm_token, platform, device_model, registered_at, last_seen_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$user->getId(), $row['fcm_token'], $row['platform'], $row['device_model'], $row['registered_at'], $row['last_seen_at']]
                );
                $stats['devices']++;
            }
            $io->writeln("  ✓ {$stats['devices']} devices");
        } catch (\Exception $e) {
            $io->warning('  devices: ' . $e->getMessage());
        }
    }

    private function importConversations(\PDO $v1, SymfonyStyle $io, array &$stats): void
    {
        try {
            $rows = $v1->query("SELECT id, type, name, created_at, updated_at FROM conversations")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $this->connection->executeStatement(
                    'INSERT INTO conversations (id, type, name, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                    [$row['id'], $row['type'], $row['name'], $row['created_at'], $row['updated_at']]
                );
                $stats['conversations']++;
            }
            $io->writeln("  ✓ {$stats['conversations']} conversations");

            // Participants
            $prows = $v1->query("SELECT conversation_id, user_id, joined_at FROM conversation_participants")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($prows as $row) {
                $user = $this->userRepo->find($row['user_id']);
                if (!$user) continue;
                $this->connection->executeStatement(
                    'INSERT INTO conversation_participants (conversation_id, user_id, joined_at) VALUES (?, ?, ?)',
                    [$row['conversation_id'], $user->getId(), $row['joined_at']]
                );
            }

            // Messages
            $mrows = $v1->query("SELECT id, conversation_id, sender_id, content, created_at FROM messages")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($mrows as $row) {
                $user = $this->userRepo->find($row['sender_id']);
                if (!$user) continue;
                $this->connection->executeStatement(
                    'INSERT INTO messages (conversation_id, sender_id, content, created_at) VALUES (?, ?, ?, ?)',
                    [$row['conversation_id'], $user->getId(), $row['content'], $row['created_at']]
                );
                $stats['messages']++;
            }
            $io->writeln("  ✓ {$stats['messages']} messages");
        } catch (\Exception $e) {
            $io->warning('  conversations: ' . $e->getMessage());
        }
    }

    private function importTradingAccounts(\PDO $v1, SymfonyStyle $io, array &$stats): void
    {
        try {
            $rows = $v1->query("SELECT id, user_id, name, broker, initial_balance, current_balance, currency, is_active, deleted_at, created_at, updated_at, emoji FROM trading_accounts WHERE deleted_at IS NULL")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $user = $this->userRepo->find($row['user_id']);
                if (!$user) continue;
                $this->connection->executeStatement(
                    'INSERT INTO trading_accounts (user_id, name, broker, initial_balance, current_balance, currency, is_active, created_at, updated_at, emoji) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$user->getId(), $row['name'], $row['broker'], $row['initial_balance'], $row['current_balance'], $row['currency'], $row['is_active'], $row['created_at'], $row['updated_at'], $row['emoji']]
                );
                $stats['trading_accounts']++;
            }
            $io->writeln("  ✓ {$stats['trading_accounts']} trading accounts");
        } catch (\Exception $e) {
            $io->warning('  trading accounts: ' . $e->getMessage());
        }
    }

    private function importConnections(\PDO $v1, SymfonyStyle $io, array &$stats): void
    {
        try {
            $rows = $v1->query("SELECT requester_id, addressee_id, status, created_at, accepted_at FROM connections")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $r = $this->userRepo->find($row['requester_id']);
                $a = $this->userRepo->find($row['addressee_id']);
                if (!$r || !$a) continue;
                $this->connection->executeStatement(
                    'INSERT INTO connections (requester_id, addressee_id, status, created_at, accepted_at) VALUES (?, ?, ?, ?, ?)',
                    [$r->getId(), $a->getId(), $row['status'], $row['created_at'], $row['accepted_at']]
                );
                $stats['connections']++;
            }
            $io->writeln("  ✓ {$stats['connections']} connections");

            $rows = $v1->query("SELECT requester_id, addressee_id, status, message, created_at, responded_at FROM access_requests")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $r = $this->userRepo->find($row['requester_id']);
                $a = $this->userRepo->find($row['addressee_id']);
                if (!$r || !$a) continue;
                $this->connection->executeStatement(
                    'INSERT INTO access_requests (requester_id, addressee_id, status, message, created_at, responded_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$r->getId(), $a->getId(), $row['status'], $row['message'], $row['created_at'], $row['responded_at']]
                );
                $stats['access_requests']++;
            }
            $io->writeln("  ✓ {$stats['access_requests']} access requests");
        } catch (\Exception $e) {
            $io->warning('  connections: ' . $e->getMessage());
        }
    }

    private function importFeedPosts(\PDO $v1, SymfonyStyle $io, array &$stats): void
    {
        try {
            $rows = $v1->query("SELECT id, author_id, content, attachment_url, signal_type, asset_symbol, direction, entry_price, stop_loss, take_profit_1, take_profit_2, like_count, comment_count, status, created_at FROM feed_posts")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $author = $this->userRepo->find($row['author_id']);
                if (!$author) continue;
                $this->connection->executeStatement(
                    'INSERT INTO feed_posts (author_id, content, attachment_url, signal_type, asset_symbol, direction, entry_price, stop_loss, take_profit_1, take_profit_2, like_count, comment_count, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$author->getId(), $row['content'], $row['attachment_url'], $row['signal_type'], $row['asset_symbol'], $row['direction'], $row['entry_price'], $row['stop_loss'], $row['take_profit_1'], $row['take_profit_2'], $row['like_count'], $row['comment_count'], $row['status'], $row['created_at']]
                );
                $stats['feed_posts']++;
            }
            $io->writeln("  ✓ {$stats['feed_posts']} feed posts");
        } catch (\Exception $e) {
            $io->warning('  feed posts: ' . $e->getMessage());
        }
    }

    private function importNotifications(\PDO $v1, SymfonyStyle $io, array &$stats): void
    {
        try {
            $rows = $v1->query("SELECT id, recipient_id, actor_id, type, message, link, is_read, created_at FROM notifications")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $recipient = $this->userRepo->find($row['recipient_id']);
                if (!$recipient) continue;
                $actor = null;
                if (!empty($row['actor_id'])) {
                    $actor = $this->userRepo->find($row['actor_id']);
                }
                $this->connection->executeStatement(
                    'INSERT INTO notifications (recipient_id, actor_id, type, message, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$recipient->getId(), $actor?->getId(), $row['type'], $row['message'], $row['link'], $row['is_read'], $row['created_at']]
                );
                $stats['notifications']++;
            }
            $io->writeln("  ✓ {$stats['notifications']} notifications");
        } catch (\Exception $e) {
            $io->warning('  notifications: ' . $e->getMessage());
        }
    }
}
