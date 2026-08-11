<?php

namespace App\Controller\Api;

use App\Entity\Duel;
use App\Entity\DuelRound;
use App\Entity\User;
use App\Entity\WalletTransaction;
use App\Repository\DuelRepository;
use App\Repository\UserRepository;
use App\Security\RateLimiterTrait;
use App\Service\RateLimiterService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\LockMode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/duels')]
class DuelController extends AbstractController
{
    use RateLimiterTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private DuelRepository $duelRepository,
        private UserRepository $userRepository,
        private RateLimiterService $rateLimiter,
    ) {}

    private function getCurrentUser(Request $request): ?User
    {
        $user = $this->getUser();
        if ($user instanceof User) return $user;
        $code = trim($request->headers->get('X-Game-Code', ''));
        if (!$code) return null;
        return $this->userRepository->findOneBy(['code' => $code, 'active' => true]);
    }

    private function generateDuelCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = 'DUEL-';
            for ($i = 0; $i < 4; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } while ($this->duelRepository->findByCode($code));
        return $code;
    }

    #[Route('', name: 'api_duels_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['error' => 'No autenticado'], 401);
        }

        $waiting = $this->duelRepository->createQueryBuilder('d')
            ->where('d.status = :st')
            ->andWhere('d.player1 != :me')
            ->andWhere('d.player2 IS NULL')
            ->setParameter('st', Duel::STATUS_WAITING)
            ->setParameter('me', $user)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(20)
            ->getQuery()
            ->getResult();

        $myActive = $this->duelRepository->findActiveByUser($user);

        $format = function (Duel $d) {
            return [
                'id' => $d->getId(),
                'code' => $d->getCode(),
                'entry_fee' => $d->getEntryFee(),
                'prize_pool' => $d->getPrizePool(),
                'total_rounds' => $d->getTotalRounds(),
                'current_round' => $d->getCurrentRound(),
                'status' => $d->getStatus(),
                'player1' => $d->getPlayer1()->getCode(),
                'player2' => $d->getPlayer2()?->getCode(),
                'winner' => $d->getWinner()?->getCode(),
            ];
        };

        return $this->json([
            'waiting' => array_map($format, $waiting),
            'my_active' => array_map($format, $myActive),
        ]);
    }

    #[Route('/create', name: 'api_duels_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['error' => 'No autenticado'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $entryFee = max(0, (float) ($data['entry_fee'] ?? 0));
        $totalRounds = min(10, max(3, (int) ($data['total_rounds'] ?? 5)));

        if ($entryFee > 0 && !$user->hasBalance($entryFee)) {
            return $this->json(['error' => 'Saldo insuficiente en wallet'], 400);
        }

        if ($entryFee > 0) {
            $user->subtractFromWallet($entryFee);
        }

        $duel = new Duel();
        $duel->setCode($this->generateDuelCode());
        $duel->setPlayer1($user);
        $duel->setEntryFee(number_format($entryFee, 2, '.', ''));
        $duel->setPrizePool(number_format($entryFee * 2, 2, '.', ''));
        $duel->setTotalRounds($totalRounds);
        $duel->setStartingPrice('0.0000');

        $this->em->persist($duel);

        if ($entryFee > 0) {
            $tx = new WalletTransaction();
            $tx->setUser($user);
            $tx->setType(WalletTransaction::TYPE_DUEL_ENTRY);
            $tx->setAmount(number_format(-$entryFee, 2, '.', ''));
            $tx->setCurrency('USD');
            $tx->setStatus(WalletTransaction::STATUS_CONFIRMED);
            $tx->setNotes('Duelo ' . $duel->getCode() . ' - Entrada');
            $this->em->persist($tx);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'duel' => [
                'id' => $duel->getId(),
                'code' => $duel->getCode(),
                'entry_fee' => $duel->getEntryFee(),
                'prize_pool' => $duel->getPrizePool(),
                'total_rounds' => $duel->getTotalRounds(),
                'status' => $duel->getStatus(),
                'player1' => $user->getCode(),
            ],
        ], 201);
    }

    #[Route('/join', name: 'api_duels_join', methods: ['POST'])]
    public function join(Request $request): JsonResponse
    {
        $rateLimit = $this->checkRateLimit($request, 'duel_join', 10, 60);
        if ($rateLimit) return $rateLimit;

        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['error' => 'No autenticado'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $duelCode = strtoupper(trim($data['duel_code'] ?? $data['code'] ?? ''));

        if (!$duelCode) {
            return $this->json(['error' => 'Código de duelo requerido'], 400);
        }

        $this->em->getConnection()->beginTransaction();
        try {
            $duel = $this->duelRepository->createQueryBuilder('d')
                ->where('d.code = :code')
                ->setParameter('code', $duelCode)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult();

            if (!$duel) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'Duelo no encontrado'], 404);
            }

            if (!$duel->isWaiting()) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'Este duelo ya no está disponible'], 400);
            }
            if ($duel->getPlayer1()->getId() === $user->getId()) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'No podés unirte a tu propio duelo'], 400);
            }

            $entryFee = (float) $duel->getEntryFee();
            if ($entryFee > 0 && !$user->hasBalance($entryFee)) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'Saldo insuficiente en wallet'], 400);
            }

            if ($entryFee > 0) {
                $user->subtractFromWallet($entryFee);
            }

            $duel->setPlayer2($user);
            $duel->setStatus(Duel::STATUS_ACTIVE);
            $duel->setCurrentRound(1);
            $duel->setStartedAt(new \DateTimeImmutable());

            if ($entryFee > 0) {
                $tx = new WalletTransaction();
                $tx->setUser($user);
                $tx->setType(WalletTransaction::TYPE_DUEL_ENTRY);
                $tx->setAmount(number_format(-$entryFee, 2, '.', ''));
                $tx->setCurrency('USD');
                $tx->setStatus(WalletTransaction::STATUS_CONFIRMED);
                $tx->setNotes('Duelo ' . $duel->getCode() . ' - Entrada');
                $this->em->persist($tx);
            }

            $this->em->flush();
            $this->em->getConnection()->commit();

            return $this->json([
                'success' => true,
                'duel' => [
                    'id' => $duel->getId(),
                    'code' => $duel->getCode(),
                    'entry_fee' => $duel->getEntryFee(),
                    'prize_pool' => $duel->getPrizePool(),
                    'total_rounds' => $duel->getTotalRounds(),
                    'current_round' => $duel->getCurrentRound(),
                    'status' => $duel->getStatus(),
                    'player1' => $duel->getPlayer1()->getCode(),
                    'player2' => $user->getCode(),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            return $this->json(['error' => 'Error al unirse al duelo'], 500);
        }
    }

    #[Route('/{id}', name: 'api_duels_get', methods: ['GET'])]
    public function get(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['error' => 'No autenticado'], 401);
        }

        $duel = $this->duelRepository->find($id);
        if (!$duel) {
            return $this->json(['error' => 'Duelo no encontrado'], 404);
        }
        if (!$duel->hasPlayer($user)) {
            return $this->json(['error' => 'No sos participante de este duelo'], 403);
        }

        $roundsData = [];
        foreach ($duel->getRounds() as $round) {
            $r = [
                'round' => $round->getRoundNumber(),
                'open' => $round->getOpenPrice(),
                'close' => $round->getClosePrice(),
                'high' => $round->getHighPrice(),
                'low' => $round->getLowPrice(),
            ];
            if ($round->isBothPlayed()) {
                $r['p1_pnl'] = $round->getPlayer1Pnl();
                $r['p2_pnl'] = $round->getPlayer2Pnl();
            }
            $roundsData[] = $r;
        }

        return $this->json([
            'duel' => [
                'id' => $duel->getId(),
                'code' => $duel->getCode(),
                'entry_fee' => $duel->getEntryFee(),
                'prize_pool' => $duel->getPrizePool(),
                'total_rounds' => $duel->getTotalRounds(),
                'current_round' => $duel->getCurrentRound(),
                'status' => $duel->getStatus(),
                'player1' => $duel->getPlayer1()->getCode(),
                'player2' => $duel->getPlayer2()?->getCode(),
                'winner' => $duel->getWinner()?->getCode(),
                'p1_pnl' => $duel->getPlayer1Pnl(),
                'p2_pnl' => $duel->getPlayer2Pnl(),
                'your_turn' => $this->isYourTurn($duel, $user),
                'rounds' => $roundsData,
            ],
        ]);
    }

    #[Route('/{id}/play', name: 'api_duels_play', methods: ['POST'])]
    public function play(int $id, Request $request): JsonResponse
    {
        $rateLimit = $this->checkRateLimit($request, 'duel_play', 10, 30);
        if ($rateLimit) return $rateLimit;

        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['error' => 'No autenticado'], 401);
        }

        $this->em->getConnection()->beginTransaction();
        try {
            $duel = $this->em->find(Duel::class, $id, LockMode::PESSIMISTIC_WRITE);
            if (!$duel) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'Duelo no encontrado'], 404);
            }
            if (!$duel->isActive()) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'El duelo no está activo'], 400);
            }
            if (!$duel->hasPlayer($user)) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'No sos participante'], 403);
            }

            $data = json_decode($request->getContent(), true);
            $move = strtolower(trim($data['move'] ?? ''));
            if (!in_array($move, ['long', 'short', 'skip'], true)) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'Movimiento inválido. Usá long, short o skip'], 400);
            }

            $currentRound = $duel->getCurrentRound();

            $round = $this->em->getRepository(DuelRound::class)
                ->findOneBy(['duel' => $duel, 'roundNumber' => $currentRound]);

            if (!$round) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'Ronda no encontrada. Esperá a que el host genere la ronda'], 400);
            }

            $isP1 = $duel->getPlayer1()->getId() === $user->getId();
            if ($isP1) {
                if ($round->getPlayer1Move() !== null) {
                    $this->em->getConnection()->rollBack();
                    return $this->json(['error' => 'Ya jugaste esta ronda'], 400);
                }
                $round->setPlayer1Move($move);
            } else {
                if ($round->getPlayer2Move() !== null) {
                    $this->em->getConnection()->rollBack();
                    return $this->json(['error' => 'Ya jugaste esta ronda'], 400);
                }
                $round->setPlayer2Move($move);
            }

            if ($round->isBothPlayed()) {
                $round->computePnl();
                $p1Pnl = (float) $duel->getPlayer1Pnl() + (float) $round->getPlayer1Pnl();
                $p2Pnl = (float) $duel->getPlayer2Pnl() + (float) $round->getPlayer2Pnl();
                $duel->setPlayer1Pnl(number_format($p1Pnl, 4, '.', ''));
                $duel->setPlayer2Pnl(number_format($p2Pnl, 4, '.', ''));

                if ($currentRound >= $duel->getTotalRounds()) {
                    $this->finishDuel($duel);
                } else {
                    $duel->setCurrentRound($currentRound + 1);
                }
            }

            $this->em->flush();
            $this->em->getConnection()->commit();

            return $this->json([
                'success' => true,
                'round' => $currentRound,
                'waiting_for_opponent' => !$round->isBothPlayed(),
                'duel' => [
                    'current_round' => $duel->getCurrentRound(),
                    'status' => $duel->getStatus(),
                    'p1_pnl' => $duel->getPlayer1Pnl(),
                    'p2_pnl' => $duel->getPlayer2Pnl(),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            return $this->json(['error' => 'Error al procesar jugada'], 500);
        }
    }

    #[Route('/{id}/next-round', name: 'api_duels_next_round', methods: ['POST'])]
    public function nextRound(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['error' => 'No autenticado'], 401);
        }

        $this->em->getConnection()->beginTransaction();
        try {
            $duel = $this->em->find(Duel::class, $id, LockMode::PESSIMISTIC_WRITE);
            if (!$duel) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'Duelo no encontrado'], 404);
            }
            if (!$duel->isActive()) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'El duelo no está activo'], 400);
            }

            $isP1 = $duel->getPlayer1()->getId() === $user->getId();
            if (!$isP1) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'Solo el creador puede generar la siguiente ronda'], 403);
            }

            $data = json_decode($request->getContent(), true);
            $openPrice = (float) ($data['open'] ?? 0);
            $closePrice = (float) ($data['close'] ?? 0);
            $highPrice = (float) ($data['high'] ?? $closePrice);
            $lowPrice = (float) ($data['low'] ?? $openPrice);

            if ($openPrice <= 0 || $closePrice <= 0) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'Precios inválidos'], 400);
            }

            $round = new DuelRound();
            $round->setDuel($duel);
            $round->setRoundNumber($duel->getCurrentRound());
            $round->setOpenPrice(number_format($openPrice, 4, '.', ''));
            $round->setClosePrice(number_format($closePrice, 4, '.', ''));
            $round->setHighPrice(number_format($highPrice, 4, '.', ''));
            $round->setLowPrice(number_format($lowPrice, 4, '.', ''));

            if ($duel->getStartingPrice() === '0.0000') {
                $duel->setStartingPrice($round->getOpenPrice());
            }

            $this->em->persist($round);
            $this->em->flush();
            $this->em->getConnection()->commit();

            return $this->json([
                'success' => true,
                'round' => $duel->getCurrentRound(),
                'candle' => [
                    'open' => $round->getOpenPrice(),
                    'close' => $round->getClosePrice(),
                    'high' => $round->getHighPrice(),
                    'low' => $round->getLowPrice(),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            return $this->json(['error' => 'Error al generar ronda'], 500);
        }
    }

    #[Route('/{id}/cancel', name: 'api_duels_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['error' => 'No autenticado'], 401);
        }

        $this->em->getConnection()->beginTransaction();
        try {
            $duel = $this->em->find(Duel::class, $id, LockMode::PESSIMISTIC_WRITE);
            if (!$duel) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'Duelo no encontrado'], 404);
            }
            if ($duel->getPlayer1()->getId() !== $user->getId()) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'Solo el creador puede cancelar'], 403);
            }
            if (!$duel->isWaiting()) {
                $this->em->getConnection()->rollBack();
                return $this->json(['error' => 'No se puede cancelar un duelo activo/finalizado'], 400);
            }

            $entryFee = (float) $duel->getEntryFee();
            if ($entryFee > 0) {
                $user->addToWallet($entryFee);
                $tx = new WalletTransaction();
                $tx->setUser($user);
                $tx->setType(WalletTransaction::TYPE_DUEL_REFUND);
                $tx->setAmount(number_format($entryFee, 2, '.', ''));
                $tx->setCurrency('USD');
                $tx->setStatus(WalletTransaction::STATUS_CONFIRMED);
                $tx->setNotes('Duelo ' . $duel->getCode() . ' - Cancelado');
                $this->em->persist($tx);
            }

            $duel->setStatus(Duel::STATUS_CANCELLED);
            $this->em->flush();
            $this->em->getConnection()->commit();

            return $this->json(['success' => true, 'status' => 'cancelled']);
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            return $this->json(['error' => 'Error al cancelar duelo'], 500);
        }
    }

    private function finishDuel(Duel $duel): void
    {
        $p1Pnl = (float) $duel->getPlayer1Pnl();
        $p2Pnl = (float) $duel->getPlayer2Pnl();
        $prizePool = (float) $duel->getPrizePool();

        $winner = null;
        if ($p1Pnl > $p2Pnl) {
            $winner = $duel->getPlayer1();
        } elseif ($p2Pnl > $p1Pnl) {
            $winner = $duel->getPlayer2();
        }

        if ($winner && $prizePool > 0) {
            $winner->addToWallet($prizePool);
            $tx = new WalletTransaction();
            $tx->setUser($winner);
            $tx->setType(WalletTransaction::TYPE_DUEL_WIN);
            $tx->setAmount(number_format($prizePool, 2, '.', ''));
            $tx->setCurrency('USD');
            $tx->setStatus(WalletTransaction::STATUS_CONFIRMED);
            $tx->setNotes('Duelo ' . $duel->getCode() . ' - Ganador');
            $this->em->persist($tx);
        } elseif ($prizePool > 0) {
            $half = $prizePool / 2;
            $duel->getPlayer1()->addToWallet($half);
            $duel->getPlayer2()->addToWallet($half);
            $tx1 = new WalletTransaction();
            $tx1->setUser($duel->getPlayer1());
            $tx1->setType(WalletTransaction::TYPE_DUEL_REFUND);
            $tx1->setAmount(number_format($half, 2, '.', ''));
            $tx1->setCurrency('USD');
            $tx1->setStatus(WalletTransaction::STATUS_CONFIRMED);
            $tx1->setNotes('Duelo ' . $duel->getCode() . ' - Empate');
            $this->em->persist($tx1);
            $tx2 = new WalletTransaction();
            $tx2->setUser($duel->getPlayer2());
            $tx2->setType(WalletTransaction::TYPE_DUEL_REFUND);
            $tx2->setAmount(number_format($half, 2, '.', ''));
            $tx2->setCurrency('USD');
            $tx2->setStatus(WalletTransaction::STATUS_CONFIRMED);
            $tx2->setNotes('Duelo ' . $duel->getCode() . ' - Empate');
            $this->em->persist($tx2);
        }

        $duel->setWinner($winner);
        $duel->setStatus(Duel::STATUS_FINISHED);
        $duel->setFinishedAt(new \DateTimeImmutable());
    }

    private function isYourTurn(Duel $duel, User $user): bool
    {
        if (!$duel->isActive()) return false;
        $currentRound = $duel->getCurrentRound();
        $round = $this->em->getRepository(DuelRound::class)
            ->findOneBy(['duel' => $duel, 'roundNumber' => $currentRound]);
        if (!$round) {
            return $duel->getPlayer1()->getId() === $user->getId();
        }
        $isP1 = $duel->getPlayer1()->getId() === $user->getId();
        if ($isP1) return $round->getPlayer1Move() === null;
        return $round->getPlayer2Move() === null;
    }
}
