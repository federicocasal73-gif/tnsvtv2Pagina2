<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Driver as DriverInterface;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware as MiddlewareInterface;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Exception\ConnectionException;
use Psr\Log\LoggerInterface;

/**
 * Retries a failed DB connection ONCE after a short pause.
 *
 * Hostinger shared MySQL intermittently refuses connections
 * (SQLSTATE[HY000] [2002] Operation not permitted) for a second or two.
 * Without this, every in-flight endpoint 500s at once on each blip.
 * Retrying only connect() is safe: nothing was sent to the server yet,
 * so this covers GET and POST alike with zero double-write risk.
 *
 * Auto-registered on all DBAL connections via the doctrine.middleware tag
 * (autoconfiguration in DoctrineBundle, no YAML needed).
 */
final class DbRetryMiddleware implements MiddlewareInterface
{
    // Public: read by the anonymous retry driver below (separate scope).
    public const RETRY_DELAY_US = 250000;

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function wrap(DriverInterface $driver): DriverInterface
    {
        $logger = $this->logger;

        return new class($driver, $logger) extends AbstractDriverMiddleware {
            public function __construct(
                DriverInterface $driver,
                private LoggerInterface $logger,
            ) {
                parent::__construct($driver);
            }

            public function connect(array $params): DriverConnection
            {
                try {
                    return parent::connect($params);
                } catch (ConnectionException $e) {
                    $this->logger->warning('[DB] connect failed, retrying once', [
                        'error' => $e->getMessage(),
                    ]);
                    usleep(DbRetryMiddleware::RETRY_DELAY_US);

                    return parent::connect($params);
                }
            }
        };
    }
}
