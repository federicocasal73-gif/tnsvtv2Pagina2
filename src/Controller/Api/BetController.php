<?php

namespace App\Controller\Api;

use App\Entity\PlayerBet;
use App\Repository\PlayerBetRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/bets')]
class BetController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private PlayerBetRepository $betRepo,
        private UserRepository $userRepo,
    ) {}

    #[Route('', name: 'api_bets_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        
        $pending = $this->betRepo->getUserPendingBets($user->getId());
        $history = $this->betRepo->getUserBetHistory($user->getId(), 20);
        $openChallenges = $this->betRepo->getOpenChallenges(10);
        $myChallenges = $this->betRepo->getPendingChallengesForUser($user->getId());
        $stats = $this->betRepo->getUserStats($user->getId());

        return $this->json([
            'pending' => array_map(fn($b) => $this->formatBet($b), $pending),
            'history' => array_map(fn($b) => $this->formatBet($b), $history),
            'openChallenges' => array_map(fn($b) => $this->formatBet($b), $openChallenges),
            'myChallenges' => array_map(fn($b) => $this->formatBet($b), $myChallenges),
            'stats' => $stats,
        ]);
    }

    #[Route('/create', name: 'api_bets_create', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        $data = $request->toArray();

        $amount = (int) ($data['amount'] ?? 0);
        $mode = $data['mode'] ?? 'classic';
        $opponentCode = $data['opponent'] ?? null;

        // Validate amount
        if ($amount < 10 || $amount > 10000) {
            return $this->json(['error' => 'Monto debe ser entre 10 y 10,000 coins'], 400);
        }

        // Check user balance
        if ((float) $user->getCoins() < $amount) {
            return $this->json(['error' => 'Saldo insuficiente'], 400);
        }

        // Validate mode
        $validModes = ['classic', 'survival', 'portfolio', 'random'];
        if (!in_array($mode, $validModes)) {
            return $this->json(['error' => 'Modo inválido'], 400);
        }

        $opponent = null;
        if ($opponentCode) {
            $opponent = $this->userRepo->findOneBy(['code' => $opponentCode]);
            if (!$opponent) {
                return $this->json(['error' => 'Oponente no encontrado'], 404);
            }
            if ($opponent->getId() === $user->getId()) {
                return $this->json(['error' => 'No podés desafiarte a vos mismo'], 400);
            }
            if ((float) $opponent->getCoins() < $amount) {
                return $this->json(['error' => 'El oponente no tiene saldo suficiente'], 400);
            }
        }

        // Deduct from challenger
        $user->setCoins(number_format((float) $user->getCoins() - $amount, 2, '.', ''));

        $bet = new PlayerBet();
        $bet->setChallenger($user);
        $bet->setOpponent($opponent);
        $bet->setAmount($amount);
        $bet->setMode($mode);
        $bet->setStatus($opponent ? PlayerBet::STATUS_PENDING : PlayerBet::STATUS_PENDING);
        
        // Expires in 24h if open challenge, 1h if specific opponent
        $expiresIn = $opponent ? '1 hour' : '24 hours';
        $bet->setExpiresAt(new \DateTimeImmutable("+{$expiresIn}"));

        $this->em->persist($bet);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'bet' => $this->formatBet($bet),
        ]);
    }

    #[Route('/{id}/accept', name: 'api_bets_accept', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function accept(int $id): JsonResponse
    {
        $user = $this->getUser();
        $bet = $this->betRepo->find($id);

        if (!$bet) {
            return $this->json(['error' => 'Apuesta no encontrada'], 404);
        }

        if ($bet->getStatus() !== PlayerBet::STATUS_PENDING) {
            return $this->json(['error' => 'Apuesta no está disponible'], 400);
        }

        if ($bet->isExpired()) {
            $bet->setStatus(PlayerBet::STATUS_EXPIRED);
            $this->em->flush();
            return $this->json(['error' => 'Apuesta expirada'], 400);
        }

        // Check if there's an opponent or it's an open challenge
        if ($bet->getOpponent() && $bet->getOpponent()->getId() !== $user->getId()) {
            return $this->json(['error' => 'No estás invitado a esta apuesta'], 403);
        }

        // Check balance
        if ((float) $user->getCoins() < $bet->getAmount()) {
            return $this->json(['error' => 'Saldo insuficiente'], 400);
        }

        // Deduct from opponent
        $user->setCoins(number_format((float) $user->getCoins() - $bet->getAmount(), 2, '.', ''));

        // Set opponent if open challenge
        if (!$bet->getOpponent()) {
            $bet->setOpponent($user);
        }

        $bet->setStatus(PlayerBet::STATUS_ACCEPTED);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'bet' => $this->formatBet($bet),
        ]);
    }

    #[Route('/{id}/decline', name: 'api_bets_decline', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function decline(int $id): JsonResponse
    {
        $user = $this->getUser();
        $bet = $this->betRepo->find($id);

        if (!$bet) {
            return $this->json(['error' => 'Apuesta no encontrada'], 404);
        }

        if ($bet->getStatus() !== PlayerBet::STATUS_PENDING) {
            return $this->json(['error' => 'Apuesta no está disponible'], 400);
        }

        // Refund challenger
        $challenger = $bet->getChallenger();
        $challenger->setCoins(number_format(
            (float) $challenger->getCoins() + $bet->getAmount(), 
            2, '.', ''
        ));

        $bet->setStatus(PlayerBet::STATUS_CANCELLED);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/cancel', name: 'api_bets_cancel', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function cancel(int $id): JsonResponse
    {
        $user = $this->getUser();
        $bet = $this->betRepo->find($id);

        if (!$bet) {
            return $this->json(['error' => 'Apuesta no encontrada'], 404);
        }

        if ($bet->getChallenger()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Solo podés cancelar tus propias apuestas'], 403);
        }

        if ($bet->getStatus() !== PlayerBet::STATUS_PENDING) {
            return $this->json(['error' => 'No se puede cancelar'], 400);
        }

        // Refund
        $user->setCoins(number_format(
            (float) $user->getCoins() + $bet->getAmount(), 
            2, '.', ''
        ));

        $bet->setStatus(PlayerBet::STATUS_CANCELLED);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/result', name: 'api_bets_result', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function submitResult(int $id, Request $request): JsonResponse
    {
        $user = $this->getUser();
        $bet = $this->betRepo->find($id);

        if (!$bet) {
            return $this->json(['error' => 'Apuesta no encontrada'], 404);
        }

        if ($bet->getStatus() !== PlayerBet::STATUS_ACCEPTED) {
            return $this->json(['error' => 'Apuesta no está activa'], 400);
        }

        // Verify参与者
        $isChallenger = $bet->getChallenger()->getId() === $user->getId();
        $isOpponent = $bet->getOpponent() && $bet->getOpponent()->getId() === $user->getId();
        
        if (!$isChallenger && !$isOpponent) {
            return $this->json(['error' => 'No participás en esta apuesta'], 403);
        }

        $data = $request->toArray();
        $challengerScore = $data['challengerScore'] ?? 0;
        $opponentScore = $data['opponentScore'] ?? 0;

        // Determine winner
        $winner = null;
        if ($challengerScore > $opponentScore) {
            $winner = $bet->getChallenger();
        } elseif ($opponentScore > $challengerScore) {
            $winner = $bet->getOpponent();
        }
        // If tie, no winner (refund both)

        $bet->resolve($winner, $challengerScore, $opponentScore);

        // Pay winner
        if ($winner) {
            $winner->setCoins(number_format(
                (float) $winner->getCoins() + $bet->getTotalPot(), 
                2, '.', ''
            ));
        } else {
            // Tie - refund both
            $bet->getChallenger()->setCoins(number_format(
                (float) $bet->getChallenger()->getCoins() + $bet->getAmount(), 
                2, '.', ''
            ));
            $bet->getOpponent()->setCoins(number_format(
                (float) $bet->getOpponent()->getCoins() + $bet->getAmount(), 
                2, '.', ''
            ));
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'bet' => $this->formatBet($bet),
            'winner' => $winner ? [
                'id' => $winner->getId(),
                'name' => $winner->getName(),
                'code' => $winner->getCode(),
            ] : null,
            'isTie' => $winner === null,
        ]);
    }

    #[Route('/open', name: 'api_bets_open', methods: ['GET'])]
    public function openChallenges(): JsonResponse
    {
        $challenges = $this->betRepo->getOpenChallenges(20);
        return $this->json([
            'challenges' => array_map(fn($b) => $this->formatBet($b), $challenges),
        ]);
    }

    private function formatBet(PlayerBet $bet): array
    {
        return [
            'id' => $bet->getId(),
            'challenger' => [
                'id' => $bet->getChallenger()->getId(),
                'name' => $bet->getChallenger()->getName(),
                'code' => $bet->getChallenger()->getCode(),
            ],
            'opponent' => $bet->getOpponent() ? [
                'id' => $bet->getOpponent()->getId(),
                'name' => $bet->getOpponent()->getName(),
                'code' => $bet->getOpponent()->getCode(),
            ] : null,
            'amount' => $bet->getAmount(),
            'totalPot' => $bet->getTotalPot(),
            'mode' => $bet->getMode(),
            'status' => $bet->getStatus(),
            'statusLabel' => match($bet->getStatus()) {
                'pending' => 'Esperando oponente',
                'accepted' => 'En juego',
                'completed' => 'Finalizada',
                'cancelled' => 'Cancelada',
                'expired' => 'Expirada',
                default => $bet->getStatus(),
            },
            'challengerScore' => $bet->getChallengerScore(),
            'opponentScore' => $bet->getOpponentScore(),
            'winner' => $bet->getWinner() ? [
                'id' => $bet->getWinner()->getId(),
                'name' => $bet->getWinner()->getName(),
                'code' => $bet->getWinner()->getCode(),
            ] : null,
            'createdAt' => $bet->getCreatedAt()->format('c'),
            'expiresAt' => $bet->getExpiresAt()?->format('c'),
            'isExpired' => $bet->isExpired(),
        ];
    }
}