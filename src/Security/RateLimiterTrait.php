<?php

namespace App\Security;

use App\Service\RateLimiterService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Rate limiter helper for controllers.
 * Usage: In controller constructor, set $this->rateLimiter = $rateLimiterService.
 */
trait RateLimiterTrait
{
    /**
     * Check rate limit for a given key. Returns remaining attempts.
     * Sends 429 response if exceeded.
     */
    protected function checkRateLimit(Request $request, string $action, int $maxAttempts = 30, int $windowSeconds = 60): ?JsonResponse
    {
        if (!isset($this->rateLimiter) || !$this->rateLimiter instanceof RateLimiterService) {
            return null;
        }

        $ip = $request->getClientIp() ?? 'unknown';
        $key = $action . ':' . $ip;

        $remaining = $this->rateLimiter->checkAndHit($key, $maxAttempts, $windowSeconds);
        if ($remaining < 0) {
            return new JsonResponse([
                'error' => 'too_many_requests',
                'message' => 'Demasiadas solicitudes. Esperá antes de intentar de nuevo.',
                'retry_after' => $windowSeconds,
            ], 429);
        }

        return null;
    }
}
