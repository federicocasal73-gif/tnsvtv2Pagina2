<?php

namespace App\Controller\Api\Sanctum;

use App\Entity\User;
use App\Service\Oracle\OracleMetricsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Oráculo de Métricas API — Phase 4.
 * All endpoints require authentication. The "self" scope reads
 * the current logged-in user; otherwise pass ?code=USR_xxx.
 */
#[Route('/sanctum/api/oracle', name: 'sanctum_api_oracle_')]
class OracleController extends AbstractController
{
    public function __construct(
        private OracleMetricsService $oracle,
    ) {}

    private function resolveUserCode(Request $request): string
    {
        $code = $request->query->get('code');
        if ($code) return $code;
        /** @var User|null $user */
        $user = $this->getUser();
        if ($user) return $user->getCode();
        return 'DEMO'; // fallback
    }

    private function resolveRange(Request $request): array
    {
        $days = max(1, min(365, (int)$request->query->get('days', 30)));
        $to = new \DateTimeImmutable('now');
        $from = $to->modify("-{$days} days");
        return [$from, $to];
    }

    #[Route('/emotional-bias', name: 'emotional_bias', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function emotionalBias(Request $request): JsonResponse
    {
        $code = $this->resolveUserCode($request);
        [$from, $to] = $this->resolveRange($request);
        $map = $this->oracle->getEmotionalBiasMap($code, $from, $to);

        return $this->json([
            'success' => true,
            'user' => $code,
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'count' => count($map),
            'points' => $map,
        ]);
    }

    #[Route('/faith-logic', name: 'faith_logic', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function faithLogic(Request $request): JsonResponse
    {
        $code = $this->resolveUserCode($request);
        [$from, $to] = $this->resolveRange($request);
        $gauge = $this->oracle->getFaithVsLogicGauge($code, $from, $to);

        return $this->json([
            'success' => true,
            'user' => $code,
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'gauge' => $gauge,
        ]);
    }

    #[Route('/session-performance', name: 'session_performance', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function sessionPerformance(Request $request): JsonResponse
    {
        $code = $this->resolveUserCode($request);
        [$from, $to] = $this->resolveRange($request);
        $perf = $this->oracle->getSessionPerformance($code, $from, $to);

        return $this->json([
            'success' => true,
            'user' => $code,
            'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'days' => $perf,
        ]);
    }

    #[Route('/global-stats', name: 'global_stats', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function globalStats(Request $request): JsonResponse
    {
        $days = max(1, min(365, (int)$request->query->get('days', 30)));
        $stats = $this->oracle->getGlobalStats($days);

        return $this->json([
            'success' => true,
            'stats' => $stats,
        ]);
    }
}