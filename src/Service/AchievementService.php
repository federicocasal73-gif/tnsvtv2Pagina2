<?php

namespace App\Service;

use App\Entity\Achievement;
use App\Entity\UserAchievement;
use App\Repository\AchievementRepository;
use App\Repository\UserAchievementRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * TNSVT AchievementService — Fase 5.
 *
 * Evaluates each active achievement's criterion against the user's
 * current stats (lessons completed, current streak, quiz perfect scores,
 * etc.) and grants any unmet-but-earned achievement. Idempotent — calling
 * twice does not double-grant.
 */
class AchievementService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AchievementRepository $achievementRepo,
        private UserAchievementRepository $userAchievementRepo,
        private StreakService $streakService,
        private LoggerInterface $logger,
    ) {}

    /**
     * Evaluate all achievements for the given user. Newly-unlocked
     * achievements are persisted and returned.
     *
     * @return UserAchievement[]
     */
    public function evaluateFor(string $userCode): array
    {
        $stats = $this->computeStats($userCode);
        $streak = $this->streakService->getStreak($userCode);

        $newlyUnlocked = [];
        foreach ($this->achievementRepo->findActiveOrdered() as $achievement) {
            if ($this->userAchievementRepo->hasAchievement($userCode, $achievement->getId())) {
                continue;
            }
            if (!$this->meetsCriterion($achievement, $stats, $streak)) {
                continue;
            }

            $ua = new UserAchievement();
            $ua->setUserCode($userCode);
            $ua->setAchievement($achievement);
            $ua->setContext([
                'streak' => $streak['current'],
                'lessons_completed' => $stats['lessons_completed'],
                'perfect_quizzes' => $stats['perfect_quizzes'],
            ]);
            $this->em->persist($ua);
            $newlyUnlocked[] = $ua;
        }
        if ($newlyUnlocked) {
            $this->em->flush();
            $this->logger->info('[achievements] unlocked', [
                'user' => $userCode, 'count' => count($newlyUnlocked),
            ]);
        }
        return $newlyUnlocked;
    }

    /**
     * Returns the list of unlocked achievements for the given user.
     *
     * @return UserAchievement[]
     */
    public function getUserAchievements(string $userCode): array
    {
        return $this->userAchievementRepo->findByUser($userCode);
    }

    /**
     * Compute aggregate stats for the given user from existing tables.
     *
     * @return array{
     *   lessons_completed: int,
     *   tasks_submitted: int,
     *   tasks_approved: int,
     *   perfect_quizzes: int,
     *   perfect_quiz_total: int,
     * }
     */
    public function computeStats(string $userCode): array
    {
        $conn = $this->em->getConnection();
        $userId = (int) $conn->fetchOne('SELECT id FROM users WHERE code = :c', ['c' => $userCode]);

        $row = $conn->fetchAssociative(
            'SELECT
                (SELECT COUNT(*) FROM campus_lesson_progress WHERE user_code = :u AND completed = 1) AS lessons_completed,
                (SELECT COUNT(*) FROM task_submissions WHERE user_id = :uid) AS tasks_submitted,
                (SELECT COUNT(*) FROM task_submissions WHERE user_id = :uid AND status = :approved) AS tasks_approved',
            ['u' => $userCode, 'uid' => $userId, 'approved' => 'approved']
        );

        // Perfect quiz: a submission where percent = 100
        $perfectQuizzes = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM campus_quiz_submissions WHERE user_code = :u AND percent = 100',
            ['u' => $userCode]
        );

        return [
            'lessons_completed' => (int) ($row['lessons_completed'] ?? 0),
            'tasks_submitted' => (int) ($row['tasks_submitted'] ?? 0),
            'tasks_approved' => (int) ($row['tasks_approved'] ?? 0),
            'perfect_quizzes' => $perfectQuizzes,
            'perfect_quiz_total' => $perfectQuizzes,
        ];
    }

    /**
     * Returns true if the user's stats satisfy the achievement's criterion.
     *
     * @param array<string, int> $stats
     * @param array<string, mixed> $streak
     */
    public function meetsCriterion(Achievement $ach, array $stats, array $streak): bool
    {
        $value = $ach->getCriterionValue();
        return match ($ach->getCriterionType()) {
            'count_lessons' => $stats['lessons_completed'] >= $value,
            'count_tasks' => $stats['tasks_submitted'] >= $value,
            'count_approved' => $stats['tasks_approved'] >= $value,
            'count_perfect_quizzes' => $stats['perfect_quizzes'] >= $value,
            'streak_days' => $streak['current'] >= $value,
            'special' => false, // special achievements only granted manually
            default => false,
        };
    }

    /**
     * Seed the default achievement catalog. Idempotent (uses slug unique).
     *
     * @return int Number of newly-seeded achievements
     */
    public function seedDefaults(): int
    {
        $defaults = [
            ['slug' => 'first-lesson', 'title' => 'Primer paso', 'description' => 'Completaste tu primera lección', 'icon' => 'school', 'color' => 'gold', 'points' => 10, 'category' => Achievement::CATEGORY_LEARNING, 'criterionType' => 'count_lessons', 'criterionValue' => 1],
            ['slug' => 'fast-learner', 'title' => 'Aprendiz veloz', 'description' => 'Completaste 5 lecciones', 'icon' => 'rocket_launch', 'color' => 'violet', 'points' => 25, 'category' => Achievement::CATEGORY_LEARNING, 'criterionType' => 'count_lessons', 'criterionValue' => 5],
            ['slug' => 'dedicated-student', 'title' => 'Estudiante dedicado', 'description' => 'Completaste 25 lecciones', 'icon' => 'auto_stories', 'color' => 'gold', 'points' => 100, 'category' => Achievement::CATEGORY_MASTERY, 'criterionType' => 'count_lessons', 'criterionValue' => 25],
            ['slug' => 'module-master', 'title' => 'Maestro del módulo', 'description' => 'Completaste 100 lecciones', 'icon' => 'workspace_premium', 'color' => 'gold', 'points' => 500, 'category' => Achievement::CATEGORY_MASTERY, 'criterionType' => 'count_lessons', 'criterionValue' => 100],
            ['slug' => 'streak-3', 'title' => 'Constancia', 'description' => '3 días seguidos aprendiendo', 'icon' => 'local_fire_department', 'color' => 'fire', 'points' => 20, 'category' => Achievement::CATEGORY_STREAK, 'criterionType' => 'streak_days', 'criterionValue' => 3],
            ['slug' => 'streak-7', 'title' => 'Una semana completa', 'description' => '7 días seguidos aprendiendo', 'icon' => 'whatshot', 'color' => 'fire', 'points' => 50, 'category' => Achievement::CATEGORY_STREAK, 'criterionType' => 'streak_days', 'criterionValue' => 7],
            ['slug' => 'streak-30', 'title' => 'Mes invicto', 'description' => '30 días seguidos aprendiendo', 'icon' => 'local_fire_department', 'color' => 'fire', 'points' => 250, 'category' => Achievement::CATEGORY_STREAK, 'criterionType' => 'streak_days', 'criterionValue' => 30],
            ['slug' => 'task-doer', 'title' => 'Cumplidor', 'description' => 'Enviaste tu primera tarea', 'icon' => 'task_alt', 'color' => 'emerald', 'points' => 15, 'category' => Achievement::CATEGORY_LEARNING, 'criterionType' => 'count_tasks', 'criterionValue' => 1],
            ['slug' => 'task-expert', 'title' => 'Experto en tareas', 'description' => 'Aprobaste 10 tareas', 'icon' => 'verified', 'color' => 'emerald', 'points' => 100, 'category' => Achievement::CATEGORY_MASTERY, 'criterionType' => 'count_approved', 'criterionValue' => 10],
            ['slug' => 'quiz-ace', 'title' => 'As del quiz', 'description' => 'Perfect score en tu primer quiz', 'icon' => 'quiz', 'color' => 'ice', 'points' => 30, 'category' => Achievement::CATEGORY_MASTERY, 'criterionType' => 'count_perfect_quizzes', 'criterionValue' => 1],
            ['slug' => 'quiz-master', 'title' => 'Maestro del quiz', 'description' => '5 quizzes con 100%', 'icon' => 'psychology', 'color' => 'ice', 'points' => 150, 'category' => Achievement::CATEGORY_MASTERY, 'criterionType' => 'count_perfect_quizzes', 'criterionValue' => 5],
        ];

        $created = 0;
        foreach ($defaults as $d) {
            if ($this->achievementRepo->findBySlug($d['slug'])) continue;
            $ach = new Achievement();
            $ach->setSlug($d['slug']);
            $ach->setTitle($d['title']);
            $ach->setDescription($d['description']);
            $ach->setIcon($d['icon']);
            $ach->setColor($d['color']);
            $ach->setPoints($d['points']);
            $ach->setCategory($d['category']);
            $ach->setCriterionType($d['criterionType']);
            $ach->setCriterionValue($d['criterionValue']);
            $ach->setOrden($created);
            $this->em->persist($ach);
            $created++;
        }
        if ($created) {
            $this->em->flush();
        }
        return $created;
    }
}
