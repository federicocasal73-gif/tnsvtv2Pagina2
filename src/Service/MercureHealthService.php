<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Periodic health probe for the Mercure SSE hub.
 *
 * Why this exists (Risk #2 in RISK_MITIGATION.md):
 *   Mercure is the realtime transport for chat, notifications, and tournaments.
 *   On Hostinger shared the hub is NOT running (`.well-known/mercure`
 *   returns 404 in prod). When the publisher fails, the chat controller
 *   catches the exception silently — messages still save to DB, but
 *   realtime delivery is broken and no one notices until users complain
 *   "messages don't arrive instantly".
 *
 *   This service provides:
 *     1. `check()` — issues a tiny publish to a `/__health/mercure` topic
 *        with a 30-second result cache (so calling it 100×/s doesn't
 *        hammer the hub).
 *     2. Failure streak tracking + critical-log on threshold. We log ONCE
 *        per failure streak, not on every failed call, so the alert
 *        channel isn't spammed.
 *     3. Public introspection: `getLastResult()` for the health endpoint.
 *
 * Cost: a single low-priority publish every 30s in the worst case
 * (only triggered by the `/api/health/mercure` endpoint or admin probe).
 */
final class MercureHealthService
{
    /**
     * How long to cache a check result. Prevents hammering the hub if
     * the endpoint is hit frequently.
     */
    private const CACHE_TTL_SECONDS = 30;

    /**
     * Consecutive failures before we log a single critical alert.
     * (After that, only one log per failure streak; resets on recovery.)
     */
    private const ALERT_THRESHOLD = 3;

    private ?array $lastResult = null;   // ['healthy'=>bool, 'error'=>?string, 'checkedAt'=>DateTimeImmutable]
    private int $consecutiveFailures = 0;
    private bool $alertActive = false;

    public function __construct(
        private readonly HubInterface $hub,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Run a fresh health check, cache the result, return it.
     * Always performs a real publish (cached for CACHE_TTL_SECONDS).
     *
     * @return array{healthy: bool, error: ?string, checked_at: string}
     */
    public function check(): array
    {
        // Return cached result if still fresh.
        if ($this->lastResult !== null) {
            $checkedAt = $this->lastResult['checkedAt'];
            $age = time() - $checkedAt->getTimestamp();
            if ($age < self::CACHE_TTL_SECONDS) {
                return $this->shape($this->lastResult);
            }
        }

        $checkedAt = new \DateTimeImmutable();
        $healthy = false;
        $error = null;

        try {
            // Tiny payload + public topic so it doesn't depend on JWT
            // subscription (the test is "can we publish at all?").
            // HubInterface::publish() returns the URL where the update
            // was published — a non-empty string means the hub accepted it.
            $url = $this->hub->publish(new Update(
                '/__health/mercure',
                json_encode(['ping' => $checkedAt->getTimestamp()], JSON_THROW_ON_ERROR),
            ));
            if ($url !== '') {
                $healthy = true;
            } else {
                $error = 'hub returned empty URL (publish rejected)';
            }
        } catch (\Throwable $e) {
            $error = sprintf('%s: %s', $e::class, $e->getMessage());
        }

        $this->lastResult = [
            'healthy' => $healthy,
            'error' => $error,
            'checkedAt' => $checkedAt,
        ];

        if ($healthy) {
            if ($this->alertActive) {
                $this->logger->info('MercureHealth: hub recovered', [
                    'consecutive_failures_before_recovery' => $this->consecutiveFailures,
                ]);
                $this->alertActive = false;
            }
            $this->consecutiveFailures = 0;
        } else {
            $this->consecutiveFailures++;
            if (!$this->alertActive && $this->consecutiveFailures >= self::ALERT_THRESHOLD) {
                $this->logger->critical('MercureHealth: hub unreachable, SSE features degraded', [
                    'consecutive_failures' => $this->consecutiveFailures,
                    'last_error' => $error,
                    'checked_at' => $checkedAt->format(\DateTimeInterface::ATOM),
                ]);
                $this->alertActive = true;
            }
        }

        return $this->shape($this->lastResult);
    }

    /**
     * Return the cached result without issuing a new check.
     * Returns null if no check has been performed yet (cold start).
     *
     * @return array{healthy: bool, error: ?string, checked_at: string}|null
     */
    public function getLastResult(): ?array
    {
        return $this->lastResult !== null ? $this->shape($this->lastResult) : null;
    }

    /**
     * Forget the cached result. Used by the admin-only probe endpoint to
     * force a fresh check on the next call.
     */
    public function resetCache(): void
    {
        $this->lastResult = null;
    }

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    /**
     * @param array{healthy: bool, error: ?string, checkedAt: \DateTimeImmutable} $r
     * @return array{healthy: bool, error: ?string, checked_at: string}
     */
    private function shape(array $r): array
    {
        return [
            'healthy'    => $r['healthy'],
            'error'      => $r['error'],
            'checked_at' => $r['checkedAt']->format(\DateTimeInterface::ATOM),
        ];
    }
}
