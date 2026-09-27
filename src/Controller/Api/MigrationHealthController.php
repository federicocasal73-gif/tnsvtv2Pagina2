<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\MigrationHealthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Doctrine migrations health endpoint.
 *
 *   GET  /api/health/migrations          — public, returns 200 / 500
 *                                         (for UptimeRobot / Better Stack / Nagios)
 *                                         200 = in_sync (all migrations applied)
 *                                         500 = drift detected OR an error
 *   POST /api/admin/migrations/check    — admin only, forces a fresh check
 *                                         (bypasses the 10s cache)
 */
class MigrationHealthController extends AbstractController
{
    public function __construct(
        private readonly MigrationHealthService $health,
    ) {}

    #[Route('/api/health/migrations', name: 'api_health_migrations', methods: ['GET'])]
    public function probe(): JsonResponse
    {
        $result = $this->health->check();

        // 200 when in sync AND no error; 500 otherwise. UptimeRobot
        // will alert on any non-200 status code.
        $ok = ($result['in_sync'] ?? false) === true && ($result['error'] ?? null) === null;

        return new JsonResponse([
            'status'             => $ok ? 'ok' : 'drift',
            'service'            => 'migrations',
            'in_sync'            => $result['in_sync'],
            'available'          => $result['available'],
            'executed'           => $result['executed'],
            'pending'            => $result['pending'],
            'latest_available'   => $result['latest_available'],
            'latest_executed'    => $result['latest_executed'],
            'pending_versions'   => $result['pending_versions'],
            'unavailable_versions' => $result['unavailable_versions'],
            'checked_at'         => $result['checked_at'],
            'error'              => $result['error'],
        ], $ok ? Response::HTTP_OK : Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    #[Route('/api/admin/migrations/check', name: 'api_admin_migrations_force_check', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function forceCheck(): JsonResponse
    {
        $this->health->resetCache();
        $result = $this->health->check();

        $ok = ($result['in_sync'] ?? false) === true && ($result['error'] ?? null) === null;

        return new JsonResponse([
            'status'               => $ok ? 'ok' : 'drift',
            'service'              => 'migrations',
            'in_sync'              => $result['in_sync'],
            'available'            => $result['available'],
            'executed'             => $result['executed'],
            'pending'              => $result['pending'],
            'latest_available'     => $result['latest_available'],
            'latest_executed'      => $result['latest_executed'],
            'pending_versions'     => $result['pending_versions'],
            'unavailable_versions' => $result['unavailable_versions'],
            'checked_at'           => $result['checked_at'],
            'error'                => $result['error'],
        ], $ok ? Response::HTTP_OK : Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}