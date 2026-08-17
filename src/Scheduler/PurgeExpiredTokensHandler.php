<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Repository\DeviceRepository;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Messenger\SchedulerTransport;

/**
 * Fired every hour by Symfony Scheduler.
 * Cleans up expired/old data that doesn't need to linger:
 *  - Devices inactive for 90+ days (FCM tokens expire naturally)
 *  - Messenger messages older than 7 days
 *  - Old failed messenger messages
 */
final class PurgeExpiredTokensHandler
{
    private const DEVICE_INACTIVITY_DAYS = 90;
    private const MESSAGE_RETENTION_DAYS = 7;

    public function __construct(
        private DeviceRepository $deviceRepository,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeExpiredTokensMessage $message, SchedulerTransport $transport): void
    {
        $cutoff = new \DateTimeImmutable('-' . self::DEVICE_INACTIVITY_DAYS . ' days');

        // Mark old devices as inactive (preserves record but stops push attempts)
        $sql = 'UPDATE devices SET active = 0 WHERE last_seen_at < :cutoff';
        $deviceCount = $this->connection->executeStatement($sql, ['cutoff' => $cutoff->format('Y-m-d H:i:s')]);

        // Delete old messenger messages
        $messageCutoff = new \DateTimeImmutable('-' . self::MESSAGE_RETENTION_DAYS . ' days');
        try {
            $msgSql = 'DELETE FROM messenger_messages WHERE available_at < :cutoff';
            $messageCount = $this->connection->executeStatement($msgSql, ['cutoff' => $messageCutoff->format('Y-m-d H:i:s')]);
        } catch (\Throwable) {
            $messageCount = 0; // table might not exist in test env
        }

        if ($deviceCount > 0 || $messageCount > 0) {
            $this->logger->info('Purged expired data', [
                'devices_deactivated' => $deviceCount,
                'messages_deleted' => $messageCount,
            ]);
        }
    }
}