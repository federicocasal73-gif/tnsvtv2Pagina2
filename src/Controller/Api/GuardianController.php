<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Guardian\DisciplineScoreCalculator;
use App\Service\Guardian\GuardianSignalCollector;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Guardian API — Phase 1 (read-only).
 *
 * Exposes the Guardian concept as a unified surface. The endpoint is intentionally
 * cheap (no DB writes, no heavy aggregations) so it can be polled by the Sanctum
 * Home widget.
 *
 * Both endpoints require authentication. They return 401 for anonymous requests.
 */
class GuardianController extends AbstractController
{
    public function __construct(
        private GuardianSignalCollector $signalCollector,
        private DisciplineScoreCalculator $scoreCalculator,
    ) {}

    #[Route('/api/guardian/signals', name: 'api_guardian_signals', methods: ['GET'])]
    public function signals(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'error' => 'unauthorized',
            ], 401);
        }

        $signals = $this->signalCollector->collect($user);

        return $this->json([
            'success' => true,
            'count' => count($signals),
            'signals' => array_map(fn($s) => $s->toArray(), $signals),
            'computed_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/api/guardian/score', name: 'api_guardian_score', methods: ['GET'])]
    public function score(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->json([
                'success' => false,
                'error' => 'unauthorized',
            ], 401);
        }

        $result = $this->scoreCalculator->compute($user);

        return $this->json([
            'success' => true,
            'score' => $result['score'],
            'tier' => $result['tier'],
            'breakdown' => $result['breakdown'],
            'computed_at' => $result['computed_at'],
        ]);
    }
}
