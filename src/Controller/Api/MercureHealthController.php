<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\MercureHealthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mercure SSE hub health endpoint.
 *
 *   GET  /api/health/mercure          — public, returns 200 / 503
 *                                       (for UptimeRobot / Better Stack / Nagios)
 *   POST /api/admin/mercure/check    — admin only, forces a fresh probe
 *                                       (bypasses the 30s cache)
 */
class MercureHealthController extends AbstractController
{
    public function __construct(
        private readonly MercureHealthService $health,
    ) {}

    #[Route('/api/health/mercure', name: 'api_health_mercure', methods: ['GET'])]
    public function probe(): JsonResponse
    {
        // Use the cached result so a monitoring bot hitting /health every
        // 10s doesn't hammer the hub — the cache TTL is 30s. If the cache
        // is cold (first hit) we do run a real check.
        $result = $this->health->getLastResult() ?? $this->health->check();

        $status = $result['healthy'] ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse([
            'status'     => $result['healthy'] ? 'ok' : 'degraded',
            'service'    => 'mercure',
            'checked_at' => $result['checked_at'],
            'consecutive_failures' => $this->health->getConsecutiveFailures(),
            'error'      => $result['error'],
        ], $status);
    }

    #[Route('/api/admin/mercure/check', name: 'api_admin_mercure_force_check', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function forceCheck(): JsonResponse
    {
        // Bypass the 30s cache so the admin sees a real-time probe result.
        $this->health->resetCache();
        $result = $this->health->check();

        return new JsonResponse([
            'status'  => $result['healthy'] ? 'ok' : 'degraded',
            'service' => 'mercure',
            'checked_at' => $result['checked_at'],
            'consecutive_failures' => $this->health->getConsecutiveFailures(),
            'error'   => $result['error'],
        ], $result['healthy'] ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
