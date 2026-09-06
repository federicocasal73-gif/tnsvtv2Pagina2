<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\TaskRepository;
use App\Repository\UserRepository;
use App\Service\AchievementService;
use App\Service\HeatmapService;
use App\Service\StreakService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * TNSVT /api/me/* — personal stats endpoints (Fase 5).
 *
 *   GET  /api/me/streak        current + longest streak + last 30d history
 *   GET  /api/me/heatmap       activity heatmap (?weeks=12)
 *   GET  /api/me/achievements  unlocked achievements + auto-evaluate
 *   GET  /api/me/summary      aggregated stats (used by dashboard widget)
 */
#[Route('/api/me')]
class MeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private StreakService $streakService,
        private HeatmapService $heatmapService,
        private AchievementService $achievementService,
        private TaskRepository $taskRepository,
    ) {}

    #[Route('/streak', name: 'me_streak', methods: ['GET'])]
    public function streak(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $days = max(7, min(365, (int) $request->query->get('days', 30)));
        $data = $this->streakService->getStreak($user->getCode(), $days);
        return $this->json([
            'user_code' => $user->getCode(),
            'current' => $data['current'],
            'longest' => $data['longest'],
            'last_active_date' => $data['last_active_date'],
            'today_active' => $data['today_active'],
            'history' => $data['history'],
        ]);
    }

    #[Route('/heatmap', name: 'me_heatmap', methods: ['GET'])]
    public function heatmap(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        $weeks = max(1, min(52, (int) $request->query->get('weeks', 12)));
        $data = $this->heatmapService->getHeatmap($user->getCode(), $weeks);
        return $this->json($data);
    }

    #[Route('/achievements', name: 'me_achievements', methods: ['GET'])]
    public function achievements(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        // Auto-evaluate on fetch (cheap)
        $newlyUnlocked = $this->achievementService->evaluateFor($user->getCode());

        $unlocked = $this->achievementService->getUserAchievements($user->getCode());

        $totalPoints = array_sum(array_map(
            fn($ua) => $ua->getAchievement()->getPoints(),
            iterator_to_array($unlocked)
        ));

        return $this->json([
            'user_code' => $user->getCode(),
            'total_points' => $totalPoints,
            'unlocked_count' => count($unlocked),
            'newly_unlocked' => array_map(fn($ua) => [
                'slug' => $ua->getAchievement()->getSlug(),
                'title' => $ua->getAchievement()->getTitle(),
                'icon' => $ua->getAchievement()->getIcon(),
                'color' => $ua->getAchievement()->getColor(),
                'points' => $ua->getAchievement()->getPoints(),
                'unlocked_at' => $ua->getUnlockedAt()->format('c'),
            ], $newlyUnlocked),
            'unlocked' => array_map(fn($ua) => [
                'id' => $ua->getAchievement()->getId(),
                'slug' => $ua->getAchievement()->getSlug(),
                'title' => $ua->getAchievement()->getTitle(),
                'description' => $ua->getAchievement()->getDescription(),
                'icon' => $ua->getAchievement()->getIcon(),
                'color' => $ua->getAchievement()->getColor(),
                'points' => $ua->getAchievement()->getPoints(),
                'category' => $ua->getAchievement()->getCategory(),
                'unlocked_at' => $ua->getUnlockedAt()->format('c'),
                'context' => $ua->getContext(),
            ], iterator_to_array($unlocked)),
        ]);
    }

    #[Route('/summary', name: 'me_summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $stats = $this->achievementService->computeStats($user->getCode());
        $streak = $this->streakService->getStreak($user->getCode());
        $achievements = $this->achievementService->getUserAchievements($user->getCode());
        $totalPoints = array_sum(array_map(
            fn($ua) => $ua->getAchievement()->getPoints(),
            iterator_to_array($achievements)
        ));

        return $this->json([
            'user_code' => $user->getCode(),
            'stats' => $stats,
            'streak' => [
                'current' => $streak['current'],
                'longest' => $streak['longest'],
                'today_active' => $streak['today_active'],
            ],
            'achievements_count' => count($achievements),
            'total_points' => $totalPoints,
        ]);
    }

    #[Route('/upcoming-tasks', name: 'me_upcoming_tasks', methods: ['GET'])]
    public function upcomingTasks(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $days = max(1, min(30, (int) $request->query->get('days', 7)));
        $limit = max(1, min(50, (int) $request->query->get('limit', 10)));

        $tasks = $this->taskRepository->findUpcomingForUser($user, $days, $limit);
        $today = $this->taskRepository->findDueTodayForUser($user);

        return $this->json([
            'upcoming' => array_map(fn($t) => $t->toArray(), $tasks),
            'today' => array_map(fn($t) => $t->toArray(), $today),
            'upcoming_count' => count($tasks),
            'today_count' => count($today),
        ]);
    }

    private function resolveUser(Request $request): ?User
    {
        $code = trim((string) $request->headers->get('X-Game-Code', ''));
        if ($code === '') return null;
        return $this->userRepository->findOneBy(['code' => $code, 'active' => true]);
    }
}
