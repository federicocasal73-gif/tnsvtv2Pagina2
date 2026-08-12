<?php

namespace App\Controller\Api;

use App\Entity\HonorBoard;
use App\Repository\HonorBoardRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/honor')]
class HonorBoardController extends AbstractController
{
    public function __construct(
        private HonorBoardRepository $honorRepo,
    ) {}

    #[Route('/board', name: 'api_honor_board', methods: ['GET'])]
    public function board(Request $request): JsonResponse
    {
        $category = $request->query->get('category', 'most_wins');
        $period = $request->query->get('period', 'all_time');
        $season = $request->query->get('season', '');

        $validCategories = [
            'most_wins', 'highest_streak', 'best_winrate', 
            'biggest_earner', 'most_active', 'richest',
            'tournament_champion', 'clan_leader'
        ];

        if (!in_array($category, $validCategories)) {
            return $this->json(['error' => 'Categoría inválida'], 400);
        }

        $entries = $this->honorRepo->getBoard($category, $period, $season, 10);

        return $this->json([
            'category' => $category,
            'period' => $period,
            'season' => $season,
            'board' => array_map(fn($e) => [
                'rank' => $e->getRank(),
                'user' => [
                    'id' => $e->getUser()->getId(),
                    'name' => $e->getUser()->getName(),
                    'code' => $e->getUser()->getCode(),
                    'avatar' => $e->getUser()->getAvatar(),
                ],
                'value' => $e->getValue(),
                'metadata' => $e->getMetadata(),
            ], $entries),
        ]);
    }

    #[Route('/my', name: 'api_honor_my', methods: ['GET'])]
    public function myHonors(): JsonResponse
    {
        $user = $this->getUser();
        $honors = $this->honorRepo->getUserHonors($user->getId());

        return $this->json([
            'honors' => array_map(fn($h) => [
                'category' => $h->getCategory(),
                'value' => $h->getValue(),
                'rank' => $h->getRank(),
                'period' => $h->getPeriod(),
                'season' => $h->getSeason(),
            ], $honors),
        ]);
    }

    #[Route('/categories', name: 'api_honor_categories', methods: ['GET'])]
    public function categories(): JsonResponse
    {
        return $this->json([
            'categories' => [
                ['id' => 'most_wins', 'name' => '🏆 Más Victorias', 'description' => 'Jugadores con más partidas ganadas'],
                ['id' => 'highest_streak', 'name' => '🔥 Racha Más Alta', 'description' => 'Mayor racha de victorias consecutivas'],
                ['id' => 'best_winrate', 'name' => '🎯 Mejor Win Rate', 'description' => 'Mayor porcentaje de victorias'],
                ['id' => 'biggest_earner', 'name' => '💰 Mayor Ganancia', 'description' => 'Más coins ganados en total'],
                ['id' => 'most_active', 'name' => '⚡ Más Activo', 'description' => 'Más partidas jugadas'],
                ['id' => 'richest', 'name' => '👑 Más Rico', 'description' => 'Mayor saldo actual'],
                ['id' => 'tournament_champion', 'name' => '🥇 Campeón de Torneos', 'description' => 'Más torneos ganados'],
                ['id' => 'clan_leader', 'name' => '⚔️ Líder de Clan', 'description' => 'Mejores clanes'],
            ],
        ]);
    }
}