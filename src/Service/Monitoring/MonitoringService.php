<?php

namespace App\Service\Monitoring;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * MonitoringService — Phase 1d.
 * Provides health checks and system stats for the admin Monitoring UI.
 */
class MonitoringService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * Get the full system status snapshot.
     * Returns a complete array of health checks.
     */
    public function getStatus(): array
    {
        return [
            'server' => $this->getServerStatus(),
            'database' => $this->getDatabaseStatus(),
            'php' => $this->getPhpStatus(),
            'opcache' => $this->getOpcacheStatus(),
            'filesystem' => $this->getFilesystemStatus(),
            'security' => $this->getSecurityStats(),
            'business' => $this->getBusinessStats(),
            'recent_errors' => $this->getRecentErrors(),
            'recent_audit' => $this->getRecentAudit(),
            'timestamp' => date('c'),
        ];
    }

    private function getServerStatus(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'os' => PHP_OS,
            'sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'memory_used_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'uptime' => $this->getUptime(),
            'loadavg' => sys_getloadavg() ?: null,
            'hostname' => gethostname() ?: null,
        ];
    }

    private function getDatabaseStatus(): array
    {
        try {
            $conn = $this->em->getConnection();
            $version = $conn->fetchOne('SELECT VERSION()');
            $dbName = $conn->getDatabase();
            $serverVersion = $conn->getServerVersion();

            $tableCount = (int)$conn->fetchOne("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?", [$dbName]);
            $userCount = $this->userRepo->count([]);
            $activeUsers = (int)$conn->fetchOne("SELECT COUNT(*) FROM users WHERE active = 1");
            $adminUsers = (int)$conn->fetchOne("SELECT COUNT(*) FROM users WHERE roles LIKE '%ROLE_ADMIN%'");

            return [
                'status' => 'ok',
                'version' => $version,
                'server_version' => $serverVersion,
                'database' => $dbName,
                'tables' => $tableCount,
                'users_total' => $userCount,
                'users_active' => $activeUsers,
                'users_admin' => $adminUsers,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function getPhpStatus(): array
    {
        return [
            'version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'post_max_size' => ini_get('post_max_size'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'date_timezone' => ini_get('date.timezone') ?: 'UTC',
            'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status()['opcache_enabled'] ?? false,
        ];
    }

    private function getOpcacheStatus(): array
    {
        if (!function_exists('opcache_get_status')) {
            return ['status' => 'unavailable'];
        }
        $status = opcache_get_status(false);
        if (!$status) {
            return ['status' => 'disabled'];
        }
        return [
            'status' => $status['opcache_enabled'] ? 'enabled' : 'disabled',
            'memory_used_mb' => round($status['memory_usage'] / 1024 / 1024, 2),
            'memory_free_mb' => round($status['free_memory'] / 1024 / 1024, 2),
            'cached_scripts' => $status['opcache_statistics']['num_cached_scripts'] ?? 0,
            'hits' => $status['opcache_statistics']['hits'] ?? 0,
            'misses' => $status['opcache_statistics']['misses'] ?? 0,
            'memory_limit_mb' => round(($status['memory_consumption'] ?? 0) / 1024 / 1024, 2),
        ];
    }

    private function getFilesystemStatus(): array
    {
        $projectDir = dirname(__DIR__, 3);
        $varDir = $projectDir . '/var';
        $cacheDir = $varDir . '/cache';
        $logDir = $varDir . '/log';
        return [
            'project_dir' => $projectDir,
            'var_dir_exists' => is_dir($varDir),
            'var_dir_writable' => is_writable($varDir),
            'cache_dir_size_mb' => is_dir($cacheDir) ? round($this->dirSize($cacheDir) / 1024 / 1024, 2) : 0,
            'log_dir_size_mb' => is_dir($logDir) ? round($this->dirSize($logDir) / 1024 / 1024, 2) : 0,
            'disk_free_gb' => round(disk_free_space($projectDir) / 1024 / 1024 / 1024, 2),
        ];
    }

    private function getSecurityStats(): array
    {
        $conn = $this->em->getConnection();
        return [
            'admin_audit_total' => (int)$conn->fetchOne('SELECT COUNT(*) FROM admin_audit_log'),
            'admin_audit_24h' => (int)$conn->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'admin_audit_failed_24h' => (int)$conn->fetchOne("SELECT COUNT(*) FROM admin_audit_log WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) AND result = 'fail'"),
            'locked_users' => (int)$conn->fetchOne('SELECT COUNT(*) FROM users WHERE active = 0'),
        ];
    }

    private function getBusinessStats(): array
    {
        $conn = $this->em->getConnection();
        return [
            'journal_entries_total' => (int)$conn->fetchOne('SELECT COUNT(*) FROM journal_entries'),
            'journal_entries_24h' => (int)$conn->fetchOne("SELECT COUNT(*) FROM journal_entries WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)"),
            'messages_total' => (int)$conn->fetchOne('SELECT COUNT(*) FROM messages'),
            'conversations_total' => (int)$conn->fetchOne('SELECT COUNT(*) FROM conversations'),
            'tasks_active' => (int)$conn->fetchOne('SELECT COUNT(*) FROM tasks WHERE active = 1'),
            'frequency_presets' => (int)$conn->fetchOne('SELECT COUNT(*) FROM frequency_presets'),
            'economic_reminders' => (int)$conn->fetchOne('SELECT COUNT(*) FROM economic_reminders'),
            'devices' => (int)$conn->fetchOne('SELECT COUNT(*) FROM devices'),
        ];
    }

    private function getRecentErrors(): array
    {
        $logFile = dirname(__DIR__, 3) . '/var/log/prod.log';
        $logFile2 = dirname(__DIR__, 3) . '/var/log/prod-' . date('Y-m-d') . '.log';
        $files = [$logFile, $logFile2];
        $lines = [];
        foreach ($files as $f) {
            if (file_exists($f)) {
                $content = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if ($content) {
                    $recent = array_slice($content, -20);
                    foreach ($recent as $line) {
                        if (stripos($line, 'error') !== false || stripos($line, 'critical') !== false) {
                            $lines[] = $line;
                        }
                    }
                }
            }
        }
        return array_slice($lines, -10);
    }

    private function getRecentAudit(): array
    {
        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(
            "SELECT id, admin_code, action, result, ip, created_at
             FROM admin_audit_log
             ORDER BY id DESC
             LIMIT 10"
        );
        return array_map(fn($r) => [
            'id' => (int)$r['id'],
            'admin' => $r['admin_code'],
            'action' => $r['action'],
            'result' => $r['result'],
            'ip' => $r['ip'],
            'time' => $r['created_at'],
        ], $rows);
    }

    private function dirSize(string $dir): int
    {
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $size += $f->getSize();
        }
        return $size;
    }

    private function getUptime(): ?string
    {
        $uptimeFile = '/proc/uptime';
        if (!file_exists($uptimeFile)) {
            return null;
        }
        $uptime = (float)file_get_contents($uptimeFile);
        $days = floor($uptime / 86400);
        $hours = floor(($uptime % 86400) / 3600);
        $mins = floor(($uptime % 3600) / 60);
        return $days . 'd ' . $hours . 'h ' . $mins . 'm';
    }
}