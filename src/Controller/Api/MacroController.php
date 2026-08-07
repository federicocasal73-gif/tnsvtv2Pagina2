<?php

namespace App\Controller\Api;

use App\Service\Macro\NoTradeWindowService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Hilos del Mundo API — Phase 5.
 * Routes:
 *   GET /api/macro/windows       list all upcoming + active no-trade windows
 *   GET /api/macro/no-trade-window  status of current window (active/next/idle)
 *   GET /api/macro/upcoming       list upcoming high-impact events
 */
#[Route('/api/macro', name: 'api_macro_')]
class MacroController extends AbstractController
{
    public function __construct(
        private NoTradeWindowService $windowService,
    ) {}

    #[Route('/windows', name: 'windows', methods: ['GET'])]
    public function windows(Request $request): JsonResponse
    {
        $limit = max(1, min(100, (int)$request->query->get('limit', 20)));
        $windows = $this->windowService->computeWindows($limit);

        $now = new \DateTimeImmutable('now');
        $out = [];
        foreach ($windows as $w) {
            $out[] = [
                'event_id' => $w['event_id'],
                'title' => $w['title'],
                'country' => $w['country'],
                'currency' => $w['currency'],
                'importance' => $w['importance'],
                'event_time' => $w['event_time']->format('c'),
                'start' => $w['start']->format('c'),
                'end' => $w['end']->format('c'),
                'is_active' => $w['is_active'],
                'seconds_until_start' => $w['start']->getTimestamp() - $now->getTimestamp(),
                'seconds_until_end' => $w['end']->getTimestamp() - $now->getTimestamp(),
            ];
        }

        return $this->json([
            'success' => true,
            'count' => count($out),
            'now' => $now->format('c'),
            'windows' => $out,
        ]);
    }

    #[Route('/no-trade-window', name: 'no_trade_window', methods: ['GET'])]
    public function noTradeWindow(): JsonResponse
    {
        $active = $this->windowService->getActiveWindow();
        $next = $this->windowService->getNextWindow();
        $now = new \DateTimeImmutable('now');

        $response = [
            'success' => true,
            'now' => $now->format('c'),
            'is_active' => $active !== null,
        ];

        if ($active) {
            $response['active'] = [
                'event_id' => $active['event_id'],
                'title' => $active['title'],
                'country' => $active['country'],
                'currency' => $active['currency'],
                'importance' => $active['importance'],
                'start' => $active['start']->format('c'),
                'end' => $active['end']->format('c'),
                'seconds_remaining' => $active['end']->getTimestamp() - $now->getTimestamp(),
            ];
        } elseif ($next) {
            $response['next'] = [
                'event_id' => $next['event_id'],
                'title' => $next['title'],
                'country' => $next['country'],
                'currency' => $next['currency'],
                'importance' => $next['importance'],
                'start' => $next['start']->format('c'),
                'end' => $next['end']->format('c'),
                'seconds_until_start' => $next['start']->getTimestamp() - $now->getTimestamp(),
            ];
        } else {
            $response['next'] = null;
        }

        return $this->json($response);
    }

    #[Route('/upcoming', name: 'upcoming', methods: ['GET'])]
    public function upcoming(Request $request): JsonResponse
    {
        $limit = max(1, min(50, (int)$request->query->get('limit', 10)));
        $windows = $this->windowService->computeWindows($limit);

        // Filter only future windows
        $now = new \DateTimeImmutable('now');
        $upcoming = array_filter($windows, fn($w) => $w['start'] > $now);
        $upcoming = array_values($upcoming);

        $out = array_map(fn($w) => [
            'event_id' => $w['event_id'],
            'title' => $w['title'],
            'country' => $w['country'],
            'currency' => $w['currency'],
            'importance' => $w['importance'],
            'event_time' => $w['event_time']->format('c'),
            'start' => $w['start']->format('c'),
            'end' => $w['end']->format('c'),
            'seconds_until_start' => $w['start']->getTimestamp() - $now->getTimestamp(),
        ], $upcoming);

        return $this->json([
            'success' => true,
            'count' => count($out),
            'events' => $out,
        ]);
    }
}