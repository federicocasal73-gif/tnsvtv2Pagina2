<?php

namespace App\Controller\Api\Sanctum;

use App\Entity\User;
use App\Service\Oracle\OracleMetricsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Oráculo de Métricas API — Phase 4.
 * All endpoints require authentication.
 *
 * SECURITY (B5 fix): non-admin users can only query metrics for their
 * own code. Passing ?code=OTHER returns 403 unless the caller has
 * ROLE_ADMIN. Without this check any authenticated user could read
 * the trading psychology data (emotional bias / faith vs logic /
 * session performance) of any other user.
 */
#[Route('/sanctum/api/oracle', name: 'sanctum_api_oracle_')]
class OracleController extends AbstractController
{
    public function __construct(
        private OracleMetricsService $oracle,
    ) {}

    /**
     * @throws AccessDeniedHttpException when the caller asks for
     *                                   another user's metrics
     *                                   without ROLE_ADMIN.
     */
    private function resolveUserCode(Request $request): string
    {
        $code = $request->query->get('code');
        /** @var User|null $user */
        $user = $this->getUser();
        $ownCode = $user?->getCode();

        if ($code !== null && $code !== '' && $code !== $ownCode) {
            // Caller is asking for someone else's metrics.
            $isAdmin = $this->isGranted('ROLE_ADMIN');
            if (!$isAdmin) {
                throw new AccessDeniedHttpException(
                    'No podés consultar las métricas de otro usuario. ' .
                    'Pasá ?code= con tu propio código o dejalo sin ?code=.'
                );
            }
            return $code;
        }

        if ($code !== null && $code !== '') {
            return $code; // matches own code
        }

        if ($ownCode !== null) {
            return $ownCode;
        }

        return 'DEMO'; // fallback (shouldn't be reached — firewall requires auth)
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
    #[IsGranted('ROLE_ADMIN')]
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