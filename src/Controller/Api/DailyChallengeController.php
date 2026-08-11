<?php

namespace App\Controller\Api;

use App\Entity\DailyChallenge;
use App\Entity\DailyChallengeEntry;
use App\Repository\DailyChallengeEntryRepository;
use App\Repository\DailyChallengeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/daily-challenge')]
class DailyChallengeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private DailyChallengeRepository $challengeRepo,
        private DailyChallengeEntryRepository $entryRepo,
    ) {}

    #[Route('/today', name: 'api_daily_today', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function today(): JsonResponse
    {
        $challenge = $this->challengeRepo->getTodayChallenge();
        $user = $this->getUser();

        if (!$challenge) {
            return $this->json([
                'challenge' => null,
                'message' => 'No hay desafío hoy',
            ]);
        }

        $userEntry = $this->entryRepo->getUserBestScore($challenge, $user->getId());
        $userRank = $userEntry ? $this->entryRepo->getUserRank($challenge, $user->getId()) : null;
        $leaderboard = $this->entryRepo->getChallengeLeaderboard($challenge, 20);

        return $this->json([
            'challenge' => $this->formatChallenge($challenge),
            'userEntry' => $userEntry ? $this->formatEntry($userEntry) : null,
            'userRank' => $userRank,
            'leaderboard' => array_map(fn($e) => $this->formatEntry($e), $leaderboard),
            'hasParticipated' => $userEntry !== null,
        ]);
    }

    #[Route('/submit', name: 'api_daily_submit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function submit(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $challenge = $this->challengeRepo->getTodayChallenge();

        if (!$challenge) {
            return $this->json(['error' => 'No hay desafío hoy'], 400);
        }

        // Check if already participated
        if ($this->entryRepo->hasUserParticipated($challenge, $user->getId())) {
            return $this->json(['error' => 'Ya participaste hoy'], 400);
        }

        $data = $request->toArray();
        $score = (int) ($data['score'] ?? 0);
        $timeSpent = isset($data['timeSpent']) ? (int) $data['timeSpent'] : null;
        $accuracy = isset($data['accuracy']) ? (int) $data['accuracy'] : null;
        $metadata = $data['metadata'] ?? [];

        $entry = new DailyChallengeEntry();
        $entry->setChallenge($challenge);
        $entry->setUser($user);
        $entry->setScore($score);
        $entry->setTimeSpent($timeSpent);
        $entry->setAccuracy($accuracy);
        $entry->setMetadata($metadata);

        $this->em->persist($entry);
        $this->em->flush();

        // Update rank
        $rank = $this->entryRepo->getUserRank($challenge, $user->getId());
        $entry->setRank($rank);
        $this->em->flush();

        // Calculate rewards based on rank
        $rewards = $this->calculateRewards($challenge, $rank);

        return $this->json([
            'success' => true,
            'entry' => $this->formatEntry($entry),
            'rank' => $rank,
            'rewards' => $rewards,
        ]);
    }

    #[Route('/leaderboard', name: 'api_daily_leaderboard', methods: ['GET'])]
    public function leaderboard(): JsonResponse
    {
        $challenge = $this->challengeRepo->getTodayChallenge();

        if (!$challenge) {
            return $this->json(['leaderboard' => []]);
        }

        $leaderboard = $this->entryRepo->getChallengeLeaderboard($challenge, 50);

        return $this->json([
            'challenge' => $this->formatChallenge($challenge),
            'leaderboard' => array_map(fn($e) => $this->formatEntry($e), $leaderboard),
        ]);
    }

    #[Route('/history', name: 'api_daily_history', methods: ['GET'])]
    public function history(): JsonResponse
    {
        $challenges = $this->challengeRepo->getRecentChallenges(7);

        return $this->json([
            'challenges' => array_map(fn($c) => $this->formatChallenge($c), $challenges),
        ]);
    }

    #[Route('/week-top', name: 'api_daily_week_top', methods: ['GET'])]
    public function weekTop(): JsonResponse
    {
        $topPlayers = $this->entryRepo->getTopPlayersThisWeek();

        return $this->json([
            'players' => array_map(fn($e) => [
                'user' => [
                    'id' => $e->getUser()->getId(),
                    'name' => $e->getUser()->getName(),
                    'code' => $e->getUser()->getCode(),
                ],
                'score' => $e->getScore(),
                'date' => $e->getCreatedAt()->format('Y-m-d'),
            ], $topPlayers),
        ]);
    }

    private function calculateRewards(DailyChallenge $challenge, ?int $rank): array
    {
        if (!$rank) return [];

        $rewards = $challenge->getRewards();
        
        if ($rank <= 3 && isset($rewards[$rank])) {
            return $rewards[$rank];
        } elseif ($rank <= 10 && isset($rewards['top10'])) {
            return $rewards['top10'];
        } elseif (isset($rewards['participation'])) {
            return $rewards['participation'];
        }

        return ['coins' => 10, 'xp' => 5];
    }

    private function formatChallenge(DailyChallenge $c): array
    {
        return [
            'id' => $c->getId(),
            'title' => $c->getTitle(),
            'description' => $c->getDescription(),
            'type' => $c->getType(),
            'date' => $c->getDate(),
            'mode' => $c->getMode(),
            'config' => $c->getConfig(),
            'rewards' => $c->getRewards(),
            'isToday' => $c->isToday(),
        ];
    }

    private function formatEntry(DailyChallengeEntry $e): array
    {
        return [
            'id' => $e->getId(),
            'user' => [
                'id' => $e->getUser()->getId(),
                'name' => $e->getUser()->getName(),
                'code' => $e->getUser()->getCode(),
            ],
            'score' => $e->getScore(),
            'timeSpent' => $e->getTimeSpent(),
            'accuracy' => $e->getAccuracy(),
            'rank' => $e->getRank(),
            'createdAt' => $e->getCreatedAt()->format('c'),
        ];
    }
}