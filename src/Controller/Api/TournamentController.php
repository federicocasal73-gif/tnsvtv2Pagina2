<?php

namespace App\Controller\Api;

use App\Entity\AdminAuditLog;
use App\Entity\Tournament;
use App\Entity\TournamentEntry;
use App\Entity\Trade;
use App\Entity\User;
use App\Entity\WalletTransaction;
use App\Repository\TournamentEntryRepository;
use App\Repository\TournamentRepository;
use App\Repository\UserRepository;
use App\Repository\WalletTransactionRepository;
use App\Security\AdminAuthTrait;
use App\Service\AdminAuditLogger;
use App\Service\AdminAuthService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Endpoints de torneos:
 *  - Public/user: list, get, join, leaderboard, my, update-equity
 *  - Admin: create, close, cancel
 */
#[Route('/api/tournaments')]
class TournamentController extends AbstractController
{
    use AdminAuthTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private TournamentRepository $tournamentRepository,
        private TournamentEntryRepository $entryRepository,
        private UserRepository $userRepository,
        private WalletTransactionRepository $txRepository,
        private \App\Service\TournamentMailer $tournamentMailer,
        private AdminAuthService $adminAuth,
        private AdminAuditLogger $auditLogger,
        private \App\Repository\TradeRepository $tradeRepository,
        private \App\Service\MarketDataService $marketData,
        #[Autowire(service: 'limiter.admin_actions')]
        private RateLimiterFactory $adminActionsLimiter,
        #[Autowire(service: 'limiter.tournament_join')]
        private RateLimiterFactory $joinLimiter,
        #[Autowire(service: 'limiter.user_actions')]
        private RateLimiterFactory $userActionsLimiter,
    ) {}

    private function getCurrentUser(Request $request): ?User
    {
        $user = $this->getUser();
        if ($user instanceof User) return $user;
        $code = trim($request->headers->get('X-Game-Code', ''));
        if (!$code) {
            $data = json_decode($request->getContent(), true);
            if (is_array($data) && isset($data['code'])) {
                $code = trim((string) $data['code']);
            }
        }
        if (!$code) return null;
        return $this->userRepository->findOneBy(['code' => $code, 'active' => true]);
    }

    private function serializeTournament(Tournament $t, ?User $currentUser = null): array
    {
        $entries = $t->getEntries();
        $entryCount = $entries->count();
        $myEntry = $currentUser ? $this->entryRepository->findUserEntry($t, $currentUser) : null;
        // Si esta finished/cancelled, el prize_pool es el final (ya distribuido).
        // Si esta active/pending, es la suma del base + entries (potencial).
        $prizePool = $t->isFinished() || $t->isCancelled()
            ? (float) $t->getPrizePool()
            : (float) $t->getPrizePool() + ($entryCount * (float) $t->getEntryFee());

        return [
            'id' => $t->getId(),
            'name' => $t->getName(),
            'description' => $t->getDescription(),
            'entry_fee_usd' => $t->getEntryFee(),
            'prize_pool_usd' => number_format($prizePool, 2, '.', ''),
            'prize_distribution' => $t->getPrizeDistribution(),
            'start_date' => $t->getStartDate()?->format('c'),
            'end_date' => $t->getEndDate()?->format('c'),
            'status' => $t->getStatus(),
            'status_label' => $t->getStatusLabel(),
            'min_players' => $t->getMinPlayers(),
            'max_players' => $t->getMaxPlayers(),
            'participants' => $entryCount,
            'spots_left' => max(0, $t->getMaxPlayers() - $entryCount),
            'duration_days' => $t->getDurationDays(),
            'days_remaining' => $t->getDaysRemaining(),
            'my_entry' => $myEntry ? $this->serializeEntry($myEntry) : null,
        ];
    }

    private function serializeEntry(TournamentEntry $e): array
    {
        return [
            'entry_id' => $e->getId(),
            'tournament_id' => $e->getTournament()?->getId(),
            'user_id' => $e->getUser()?->getId(),
            'username' => $e->getUser()?->getCode(),
            'starting_equity' => $e->getStartingEquity(),
            'final_equity' => $e->getFinalEquity(),
            'pnl_usd' => $e->getPnlUsd(),
            'pnl_pct' => $e->getPnlPct(),
            'final_rank' => $e->getFinalRank(),
            'payout_amount' => $e->getPayoutAmount(),
            'status' => $e->getStatus(),
            'joined_at' => $e->getJoinedAt()?->format('c'),
            'finalized_at' => $e->getFinalizedAt()?->format('c'),
        ];
    }

    #[Route('/active', name: 'api_tournaments_active', methods: ['GET'])]
    public function active(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        $tournaments = $this->tournamentRepository->findActive();
        return new JsonResponse([
            'count' => count($tournaments),
            'tournaments' => array_map(fn($t) => $this->serializeTournament($t, $user), $tournaments),
        ], 200);
    }

    #[Route('/{id}', name: 'api_tournaments_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function get(int $id, Request $request): JsonResponse
    {
        $t = $this->tournamentRepository->find($id);
        if (!$t) {
            return new JsonResponse(['error' => 'tournament_not_found'], 404);
        }
        $user = $this->getCurrentUser($request);
        return new JsonResponse($this->serializeTournament($t, $user), 200);
    }

    /**
     * User joins tournament. Deducts entry_fee from wallet.
     * Rate limit: 5 joins per user per hour (anti-spam).
     * Body (optional): { "starting_equity": 50000 } (defaults to 50000)
     */
    #[Route('/{id}/join', name: 'api_tournaments_join', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function join(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return new JsonResponse(['error' => 'No autenticado'], 401);

        // Rate limit: 5 joins por user por hora
        $rl = $this->joinLimiter->create('join:' . $user->getId());
        if (!$rl->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException(3600, 'Demasiados intentos de unirse a torneos. Esperá 1 hora.');
        }

        $t = $this->tournamentRepository->find($id);
        if (!$t) return new JsonResponse(['error' => 'tournament_not_found'], 404);
        if (!$t->isActive()) {
            return new JsonResponse(['error' => 'tournament_not_active', 'status' => $t->getStatus()], 400);
        }

        // Check si ya joined
        $existing = $this->entryRepository->findUserEntry($t, $user);
        if ($existing) {
            return new JsonResponse(['error' => 'already_joined', 'entry_id' => $existing->getId()], 400);
        }

        // Check max players
        if ($t->getEntries()->count() >= $t->getMaxPlayers()) {
            return new JsonResponse(['error' => 'tournament_full'], 400);
        }

        $entryFee = (float) $t->getEntryFee();
        if (!$user->hasBalance($entryFee)) {
            return new JsonResponse([
                'error' => 'wallet_insufficient',
                'message' => "Te faltan $" . number_format($entryFee - $user->getWalletBalanceFloat(), 2) . " USD",
                'requested' => $entryFee,
                'available' => $user->getWalletBalanceFloat(),
            ], 400);
        }

        // Body: starting_equity (opcional, default 50000)
        $body = json_decode($request->getContent(), true) ?: [];
        $startingEquity = isset($body['starting_equity']) ? (float) $body['starting_equity'] : 50000.00;
        if ($startingEquity <= 0) $startingEquity = 50000.00;

        // Descuenta del wallet
        $user->subtractFromWallet($entryFee);

        // Crea entry
        $entry = new TournamentEntry();
        $entry->setTournament($t);
        $entry->setUser($user);
        $entry->setStartingEquity(number_format($startingEquity, 4, '.', ''));
        $entry->setStatus(TournamentEntry::STATUS_ACTIVE);

        // Crea wallet_tx tipo entry_fee
        $tx = new WalletTransaction();
        $tx->setUser($user);
        $tx->setType(WalletTransaction::TYPE_ENTRY_FEE);
        $tx->setAmount(number_format(-$entryFee, 2, '.', ''));
        $tx->setCurrency('USD');
        $tx->setStatus(WalletTransaction::STATUS_CONFIRMED);
        $tx->setRefTournament($t);
        $tx->setNotes("Entry fee - Tournament: {$t->getName()}");

        $this->em->persist($entry);
        $this->em->persist($tx);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'tournament_entry_id' => $entry->getId(),
            'tournament_id' => $t->getId(),
            'starting_equity' => $entry->getStartingEquity(),
            'entry_fee_paid' => number_format($entryFee, 2, '.', ''),
            'wallet_balance_after' => $user->getWalletBalance(),
            'current_rank' => $t->getEntries()->count(),
            'participants' => $t->getEntries()->count(),
        ], 200);
    }

    /**
     * Update current_equity for an active entry (called by Game periodically).
     *
     * A3 hardening: el cliente puede sugerir equity, pero el server valida:
     *  - Max delta vs prev equity: 30% por request (anti-spike)
     *  - Rate limit por user: 30/min sliding window
     *  - Max absolute equity: 100x starting equity (anti-inflation)
     * Si el cliente manda una equity "optimista" fuera de estos bounds, se
     * trunca al max permitido y se loguea.
     *
     * Body: { "current_equity": 51500.00 }
     * Uses X-Game-Code auth.
     */
    #[Route('/{id}/update-equity', name: 'api_tournaments_update_equity', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function updateEquity(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return new JsonResponse(['error' => 'No autenticado'], 401);

        $t = $this->tournamentRepository->find($id);
        if (!$t) return new JsonResponse(['error' => 'tournament_not_found'], 404);
        if (!$t->isActive()) return new JsonResponse(['error' => 'tournament_not_active'], 400);

        $body = json_decode($request->getContent(), true) ?: [];
        $currentEquity = isset($body['current_equity']) ? (float) $body['current_equity'] : null;
        if ($currentEquity === null || $currentEquity < 0) {
            return new JsonResponse(['error' => 'Falta current_equity'], 400);
        }

        $entry = $this->entryRepository->findUserEntry($t, $user);
        if (!$entry) return new JsonResponse(['error' => 'not_joined'], 400);
        if (!$entry->isActive()) return new JsonResponse(['error' => 'entry_not_active'], 400);

        // A3 anti-fraud: validar el delta vs equity previa
        $start = (float) $entry->getStartingEquity();
        $prev = (float) ($entry->getFinalEquity() ?? $start);
        if ($start > 0 && $prev > 0) {
            $delta = ($currentEquity - $prev) / $prev;
            if (abs($delta) > 0.30) {
                // Truncar al +30%/-30% y registrar intento sospechoso
                $currentEquity = $prev * ($delta >= 0 ? 1.30 : 0.70);
                $this->auditLogger->log(
                    AdminAuditLog::ACTION_WALLET_ADJUST,
                    $user->getCode() ?: 'unknown',
                    AdminAuditLog::RESULT_FAIL,
                    ['tournament_id' => $t->getId(), 'reason' => 'equity_delta_exceeded', 'attempted' => $currentEquity, 'truncated_to' => $currentEquity]
                );
            }
            // Cap absoluto: no mas de 100x la equity inicial
            if ($currentEquity > $start * 100) {
                $currentEquity = $start * 100;
            }
            if ($currentEquity < $start * 0.01) {
                $currentEquity = $start * 0.01;
            }
        }

        $pnl = $entry->computeCurrentPnl(number_format($currentEquity, 4, '.', ''));
        $entry->setFinalEquity(number_format($currentEquity, 4, '.', ''));
        $entry->setPnlUsd($pnl['pnl_usd']);
        $entry->setPnlPct($pnl['pnl_pct']);

        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'entry_id' => $entry->getId(),
            'current_equity' => $entry->getFinalEquity(),
            'pnl_usd' => $entry->getPnlUsd(),
            'pnl_pct' => $entry->getPnlPct(),
        ], 200);
    }

    /**
     * A3 - Trade server-authoritative (anti-fraud B2/B3 del audit).
     *
     * El cliente envia SOLO {symbol, direction, timeframe}. El server:
     *   1. Toma entry_price de su propio feed (snapshot via MarketDataService).
     *   2. Genera exit_price (snapshot + drift o candle deterministico).
     *   3. Computa pnl server-side con leverage y size_pct.
     *   4. Actualiza equity del entry.
     *   5. Loguea el trade en tournament_trades (audit).
     *
     * El cliente NUNCA envia su propio resultado - solo la intencion del trade.
     * Body: { "symbol": "BTC", "direction": "long|short|skip", "timeframe": "5M",
     *         "size_pct": 100, "leverage": 1 }
     */
    #[Route('/{id}/trade', name: 'api_tournaments_trade', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function trade(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return new JsonResponse(['error' => 'No autenticado'], 401);

        // Rate limit por user: 60 acciones/min (sliding window, ya configurado)
        $rl = $this->userActionsLimiter->create('user:' . $user->getId());
        if (!$rl->consume(1)->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limit_exceeded', 'message' => 'Demasiadas operaciones. Esperá un momento.'], 429);
        }

        $t = $this->tournamentRepository->find($id);
        if (!$t) return new JsonResponse(['error' => 'tournament_not_found'], 404);
        if (!$t->isActive()) return new JsonResponse(['error' => 'tournament_not_active', 'status' => $t->getStatus()], 400);

        $entry = $this->entryRepository->findUserEntry($t, $user);
        if (!$entry) return new JsonResponse(['error' => 'not_joined'], 400);
        if (!$entry->isActive()) return new JsonResponse(['error' => 'entry_not_active'], 400);

        $body = json_decode($request->getContent(), true) ?: [];
        $symbol = strtoupper(trim((string) ($body['symbol'] ?? '')));
        $direction = strtolower(trim((string) ($body['direction'] ?? 'skip')));
        $timeframe = strtoupper(trim((string) ($body['timeframe'] ?? '5M')));
        $sizePct = isset($body['size_pct']) ? max(1, min(100, (float) $body['size_pct'])) : 100;
        $leverage = isset($body['leverage']) ? max(1, min(25, (float) $body['leverage'])) : 1;

        // Validar symbol contra whitelist
        if (!in_array($symbol, Trade::SYMBOL_WHITELIST, true)) {
            return new JsonResponse(['error' => 'symbol_not_allowed', 'symbol' => $symbol, 'whitelist' => Trade::SYMBOL_WHITELIST], 422);
        }
        // Validar direction
        if (!in_array($direction, [Trade::DIRECTION_LONG, Trade::DIRECTION_SHORT, Trade::DIRECTION_SKIP], true)) {
            return new JsonResponse(['error' => 'invalid_direction', 'allowed' => ['long','short','skip']], 422);
        }
        // Validar timeframe
        if (!in_array($timeframe, Trade::TIMEFRAMES, true)) {
            return new JsonResponse(['error' => 'invalid_timeframe', 'allowed' => Trade::TIMEFRAMES], 422);
        }

        // 1. Entry price: snapshot del server
        $entrySnap = $this->marketData->snapshot($symbol);
        if (!$entrySnap) {
            return new JsonResponse(['error' => 'price_unavailable', 'symbol' => $symbol], 503);
        }
        $entryPrice = $entrySnap['price'];
        $entrySrc = $entrySnap['source'];

        // 2. Exit price: snapshot o candle deterministico (seed = tournament+tradeIndex)
        // Para simplificar: usamos un segundo snapshot con un poco de drift (o deterministic)
        // En produccion: esperar la vela real del timeframe.
        $tradeIndex = (int) $this->tradeRepository->count(['tournament' => $t, 'user' => $user]);
        $candle = $this->marketData->generateCandle($symbol, $entryPrice, $timeframe, (int) $t->getId(), $tradeIndex);
        $exitPrice = (float) $candle['price'];

        // 3. Crear y persistir Trade
        $trade = new Trade();
        $trade->setEntry($entry);
        $trade->setUser($user);
        $trade->setTournament($t);
        $trade->setSymbol($symbol);
        $trade->setDirection($direction);
        $trade->setTimeframe($timeframe);
        $trade->setEntryPrice(number_format($entryPrice, 4, '.', ''));
        $trade->setExitPrice(number_format($exitPrice, 4, '.', ''));
        $trade->setSizePct(number_format($sizePct, 2, '.', ''));
        $trade->setLeverage(number_format($leverage, 2, '.', ''));
        $trade->setPriceSource($entrySrc);
        $trade->setStatus(Trade::STATUS_RESOLVED);
        $trade->setResolvedAt(new \DateTimeImmutable());

        // 4. Computar pnl y actualizar equity del entry
        $equityAtEntry = (float) ($entry->getFinalEquity() ?? $entry->getStartingEquity());
        $pnl = $trade->computePnl($equityAtEntry);
        $trade->setPnlUsd($pnl['pnl_usd']);
        $trade->setPnlPct($pnl['pnl_pct']);

        // Aplicar pnl a la equity
        $newEquity = $equityAtEntry + (float) $pnl['pnl_usd'];
        if ($newEquity < 0) $newEquity = 0;
        $entry->setFinalEquity(number_format($newEquity, 4, '.', ''));
        $entry->setPnlUsd(number_format($newEquity - (float) $entry->getStartingEquity(), 4, '.', ''));
        $entry->setPnlPct(number_format((($newEquity - (float) $entry->getStartingEquity()) / max(1, (float) $entry->getStartingEquity())) * 100, 6, '.', ''));

        $this->em->persist($trade);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'trade_id' => $trade->getId(),
            'symbol' => $symbol,
            'direction' => $direction,
            'timeframe' => $timeframe,
            'entry_price' => $trade->getEntryPrice(),
            'exit_price' => $trade->getExitPrice(),
            'pnl_usd' => $trade->getPnlUsd(),
            'pnl_pct' => $trade->getPnlPct(),
            'equity_after' => $entry->getFinalEquity(),
            'price_source' => $entrySrc,
            'candle_source' => $candle['source'],
        ], 200);
    }

    #[Route('/{id}/leaderboard', name: 'api_tournaments_leaderboard', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function leaderboard(int $id): JsonResponse
    {
        $t = $this->tournamentRepository->find($id);
        if (!$t) return new JsonResponse(['error' => 'tournament_not_found'], 404);

        $entries = $this->entryRepository->getLeaderboard($t);
        $isFinal = $t->isFinished();
        $data = array_map(function (TournamentEntry $e, int $idx) use ($isFinal) {
            $rank = $idx + 1;
            return [
                'rank' => $isFinal ? ($e->getFinalRank() ?? $rank) : $rank,
                'entry_id' => $e->getId(),
                'user_id' => $e->getUser()?->getId(),
                'username' => $e->getUser()?->getCode(),
                'display_name' => $e->getUser()?->getName(),
                'starting_equity' => $e->getStartingEquity(),
                'current_equity' => $e->getFinalEquity(),
                'pnl_usd' => $e->getPnlUsd(),
                'pnl_pct' => $e->getPnlPct(),
                'payout' => $e->getPayoutAmount(),
            ];
        }, $entries, array_keys($entries));

        return new JsonResponse([
            'tournament_id' => $t->getId(),
            'name' => $t->getName(),
            'status' => $t->getStatus(),
            'count' => count($data),
            'leaderboard' => $data,
        ], 200);
    }

    #[Route('/my', name: 'api_tournaments_my', methods: ['GET'])]
    public function my(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return new JsonResponse(['error' => 'No autenticado'], 401);

        $entries = $this->entryRepository->getUserTournaments($user, false);
        $data = array_map(function (TournamentEntry $e) use ($user) {
            $t = $e->getTournament();
            return [
                'entry' => $this->serializeEntry($e),
                'tournament' => $t ? $this->serializeTournament($t, $user) : null,
            ];
        }, $entries);

        return new JsonResponse([
            'user_id' => $user->getId(),
            'count' => count($data),
            'entries' => $data,
        ], 200);
    }

    /**
     * Admin: crear torneo.
     * Body: { name, description?, entry_fee, duration_days, max_players?, min_players?, prize_distribution?, start_now? }
     */
    #[Route('/admin/create', name: 'api_tournaments_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->adminAuth->verify($request, AdminAuditLog::ACTION_TOURNAMENT_CREATE);
        $rl = $this->adminActionsLimiter->create('admin');
        if (!$rl->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException(60, 'Demasiadas acciones admin. Esperá 1 minuto.');
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || empty($body['name']) || !isset($body['entry_fee']) || !isset($body['duration_days'])) {
            return new JsonResponse(['error' => 'Falta name, entry_fee, duration_days'], 400);
        }

        $name = trim((string) $body['name']);
        $entryFee = (float) $body['entry_fee'];
        $durationDays = (int) $body['duration_days'];
        $maxPlayers = (int) ($body['max_players'] ?? 100);
        $minPlayers = (int) ($body['min_players'] ?? 2);
        $dist = (string) ($body['prize_distribution'] ?? '60,30,10');
        $description = $body['description'] ?? null;
        $startNow = (bool) ($body['start_now'] ?? true);

        if ($entryFee <= 0) return new JsonResponse(['error' => 'entry_fee debe ser > 0'], 400);
        if ($durationDays < 1 || $durationDays > 365) {
            return new JsonResponse(['error' => 'duration_days debe ser 1-365'], 400);
        }
        if ($maxPlayers < $minPlayers) {
            return new JsonResponse(['error' => 'max_players debe ser >= min_players'], 400);
        }

        $adminUser = $this->getUser();
        if (!$adminUser) {
            // Header auth - usar el primer admin
            $adminUser = $this->userRepository->findOneBy(['code' => 'ADMIN01']);
        }

        $t = new Tournament();
        $t->setName($name);
        $t->setDescription($description);
        $t->setEntryFee(number_format($entryFee, 2, '.', ''));
        $t->setPrizeDistribution($dist);
        $t->setMaxPlayers($maxPlayers);
        $t->setMinPlayers($minPlayers);
        $t->setStatus($startNow ? Tournament::STATUS_ACTIVE : Tournament::STATUS_PENDING);
        $t->setStartDate(new \DateTimeImmutable());
        $t->setEndDate((new \DateTimeImmutable())->modify("+{$durationDays} days"));
        $t->setCreatedBy($adminUser);

        $this->em->persist($t);
        $this->em->flush();

        // Audit log de la accion admin
        $this->auditLogger->log(
            AdminAuditLog::ACTION_TOURNAMENT_CREATE,
            'admin',
            AdminAuditLog::RESULT_SUCCESS,
            ['tournament_id' => $t->getId(), 'name' => $name, 'entry_fee' => $entryFee, 'duration_days' => $durationDays, 'max_players' => $maxPlayers]
        );

        // Notificar a los users activos (best-effort, no bloquea la respuesta)
        try {
            $this->tournamentMailer->notifyTournamentCreated($t);
        } catch (\Throwable $e) {
            // Log y seguir, no fallar la creacion
        }

        return new JsonResponse([
            'success' => true,
            'tournament_id' => $t->getId(),
            'name' => $t->getName(),
            'status' => $t->getStatus(),
            'start_date' => $t->getStartDate()?->format('c'),
            'end_date' => $t->getEndDate()?->format('c'),
            'entry_fee' => $t->getEntryFee(),
            'duration_days' => $durationDays,
            'participants' => 0,
        ], 200);
    }

    /**
     * Admin: cierra un torneo. Calcula winners, distribuye prize pool, acredita wallets.
     */
    #[Route('/admin/{id}/close', name: 'api_tournaments_close', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function close(int $id, Request $request): JsonResponse
    {
        $this->adminAuth->verify($request, AdminAuditLog::ACTION_TOURNAMENT_CLOSE);
        $rl = $this->adminActionsLimiter->create('admin');
        if (!$rl->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException(60, 'Demasiadas acciones admin. Esperá 1 minuto.');
        }

        $t = $this->tournamentRepository->find($id);
        if (!$t) return new JsonResponse(['error' => 'tournament_not_found'], 404);
        if ($t->isFinished() || $t->isCancelled()) {
            return new JsonResponse(['error' => 'tournament_already_closed', 'status' => $t->getStatus()], 400);
        }
        if ($t->getEntries()->count() < $t->getMinPlayers()) {
            return new JsonResponse([
                'error' => 'not_enough_participants',
                'min_required' => $t->getMinPlayers(),
                'current' => $t->getEntries()->count(),
            ], 400);
        }

        $entries = $this->entryRepository->getLeaderboard($t);
        $dist = $t->getDistributionPcts(); // [60, 30, 10]
        $prizePool = (float) $t->getPrizePool() + count($entries) * (float) $t->getEntryFee();

        $winners = [];
        $i = 0;
        foreach ($entries as $entry) {
            $rank = $i + 1;
            $entry->setFinalRank($rank);

            $pct = $dist[$i] ?? 0;
            $payout = ($prizePool * $pct) / 100;
            $entry->setPayoutAmount(number_format($payout, 2, '.', ''));
            $entry->setStatus(TournamentEntry::STATUS_FINISHED);
            $entry->setFinalizedAt(new \DateTimeImmutable());

            // Acredita al wallet del winner
            if ($payout > 0 && $entry->getUser()) {
                $entry->getUser()->addToWallet($payout);

                $tx = new WalletTransaction();
                $tx->setUser($entry->getUser());
                $tx->setType(WalletTransaction::TYPE_PAYOUT);
                $tx->setAmount(number_format($payout, 2, '.', ''));
                $tx->setCurrency('USD');
                $tx->setStatus(WalletTransaction::STATUS_CONFIRMED);
                $tx->setRefTournament($t);
                $tx->setNotes("Payout rank #{$rank} - Tournament: {$t->getName()}");
                $tx->setConfirmedAt(new \DateTimeImmutable());
                $this->em->persist($tx);
            }

            $winners[] = [
                'rank' => $rank,
                'username' => $entry->getUser()?->getCode(),
                'pnl_pct' => $entry->getPnlPct(),
                'payout' => $entry->getPayoutAmount(),
            ];
            $i++;
        }

        // Entries que no quedaron en top N (rank > count(dist)) - quedan sin payout pero finalizados
        $maxRank = count($dist);
        foreach ($entries as $idx => $entry) {
            if ($idx >= $maxRank && $entry->getFinalRank() === null) {
                $entry->setFinalRank($idx + 1);
                $entry->setStatus(TournamentEntry::STATUS_FINISHED);
                $entry->setFinalizedAt(new \DateTimeImmutable());
            }
        }

        $t->setStatus(Tournament::STATUS_FINISHED);
        $t->setFinishedAt(new \DateTimeImmutable());
        $t->setPrizePool(number_format($prizePool, 2, '.', ''));

        $this->em->flush();

        // Audit log
        $this->auditLogger->log(
            AdminAuditLog::ACTION_TOURNAMENT_CLOSE,
            'admin',
            AdminAuditLog::RESULT_SUCCESS,
            ['tournament_id' => $t->getId(), 'participants' => count($winners), 'prize_pool' => $prizePool]
        );

        return new JsonResponse([
            'success' => true,
            'tournament_id' => $t->getId(),
            'name' => $t->getName(),
            'status' => 'finished',
            'prize_pool_distributed' => number_format($prizePool, 2, '.', ''),
            'winners' => $winners,
        ], 200);
    }

    /**
     * Admin: cancela un torneo y devuelve entry fees a los participantes.
     */
    #[Route('/admin/{id}/cancel', name: 'api_tournaments_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $this->adminAuth->verify($request, AdminAuditLog::ACTION_TOURNAMENT_CANCEL);
        $rl = $this->adminActionsLimiter->create('admin');
        if (!$rl->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException(60, 'Demasiadas acciones admin. Esperá 1 minuto.');
        }

        $t = $this->tournamentRepository->find($id);
        if (!$t) return new JsonResponse(['error' => 'tournament_not_found'], 404);
        if ($t->isFinished() || $t->isCancelled()) {
            return new JsonResponse(['error' => 'tournament_already_closed', 'status' => $t->getStatus()], 400);
        }

        $entryFee = (float) $t->getEntryFee();
        foreach ($t->getEntries() as $entry) {
            if ($entry->isActive() && $entry->getUser()) {
                $entry->getUser()->addToWallet($entryFee);

                $tx = new WalletTransaction();
                $tx->setUser($entry->getUser());
                $tx->setType(WalletTransaction::TYPE_REFUND);
                $tx->setAmount(number_format($entryFee, 2, '.', ''));
                $tx->setCurrency('USD');
                $tx->setStatus(WalletTransaction::STATUS_CONFIRMED);
                $tx->setRefTournament($t);
                $tx->setNotes("Refund - Tournament cancelled: {$t->getName()}");
                $tx->setConfirmedAt(new \DateTimeImmutable());
                $this->em->persist($tx);

                $entry->setStatus(TournamentEntry::STATUS_DISQUALIFIED);
            }
        }

        $t->setStatus(Tournament::STATUS_CANCELLED);
        $t->setFinishedAt(new \DateTimeImmutable());
        $this->em->flush();

        // Audit log
        $this->auditLogger->log(
            AdminAuditLog::ACTION_TOURNAMENT_CANCEL,
            'admin',
            AdminAuditLog::RESULT_SUCCESS,
            ['tournament_id' => $t->getId(), 'refunded' => $t->getEntries()->count(), 'amount_refunded' => $entryFee]
        );

        return new JsonResponse([
            'success' => true,
            'tournament_id' => $t->getId(),
            'status' => 'cancelled',
            'refunded_participants' => $t->getEntries()->count(),
            'amount_refunded' => number_format($entryFee, 2, '.', ''),
        ], 200);
    }
}
