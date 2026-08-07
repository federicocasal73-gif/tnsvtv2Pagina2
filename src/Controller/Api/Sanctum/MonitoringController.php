<?php

namespace App\Controller\Api\Sanctum;

use App\Service\Monitoring\MonitoringService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sanctum Monitoring API — Phase 1d.
 * Returns real-time system status.
 */
#[Route('/sanctum/api/monitoring', name: 'sanctum_api_monitoring_')]
class MonitoringController extends AbstractController
{
    public function __construct(
        private MonitoringService $monitoring,
    ) {}

    #[Route('/status', name: 'status', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function status(): JsonResponse
    {
        return $this->json([
            'success' => true,
            'data' => $this->monitoring->getStatus(),
        ]);
    }
}