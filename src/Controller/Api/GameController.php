<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\GameScore;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * API para el juego T.N.S.V.T — Market Instinct
 *
 * Endpoints:
 *   GET  /api/game/session    — verifica sesión y devuelve XP del user
 *   POST /api/game/score      — guarda score y devuelve XP actualizado
 *   GET  /api/game/leaderboard — top scores del juego
 *   GET  /api/game/my-stats    — estadísticas del user en el juego
 */
class GameController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * Verifica la sesión del user y devuelve info básica
     * Acepta auth por sesión web (cookie) o por header X-Game-Code (para apps externas)
     */
    #[Route('/api/game/session', name: 'api_game_session', methods: ['GET'])]
    public function session(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        // Intentar autenticar por X-Game-Code header
        $user = $user ?? $this->authByCode($request);

        if (!$user) {
            return new JsonResponse([
                'authenticated' => false,
                'message' => 'Necesitás iniciar sesión en TNSVT para jugar',
            ]);
        }

        $xp = $this->getUserXp($user);
        $level = $this->getUserLevel($xp);
        $nextLevelXp = $this->xpForLevel($level + 1);

        return new JsonResponse([
            'authenticated' => true,
            'user_id' => $user->getId(),
            'username' => $user->getCode(),
            'display_name' => $user->getName(),
            'xp' => $xp,
            'level' => $level,
            'next_level_xp' => $nextLevelXp,
            'rank' => $this->getRank($level),
            'isAdmin' => $user->getIsAdmin(),
        ]);
    }

    /**
     * Autentica un user externo (T.N.S.V.T Market app) por su código
     * Devuelve datos del user si el código es válido y está activo
     */
    #[Route('/api/game/auth', name: 'api_game_auth', methods: ['POST'])]
    public function auth(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $code = trim($data['code'] ?? $request->headers->get('X-Game-Code') ?? '');

        if (!$code) {
            return new JsonResponse(['error' => 'Código requerido'], 400);
        }

        $user = $this->em->getRepository(User::class)
            ->findOneBy(['code' => $code, 'active' => true]);

        if (!$user) {
            return new JsonResponse(['error' => 'Código inválido o inactivo'], 401);
        }

        return new JsonResponse([
            'authenticated' => true,
            'user_id' => $user->getId(),
            'username' => $user->getCode(),
            'display_name' => $user->getName(),
            'xp' => $this->getUserXp($user),
            'level' => $this->getUserLevel($this->getUserXp($user)),
            'rank' => $this->getRank($this->getUserLevel($this->getUserXp($user))),
            'isAdmin' => $user->getIsAdmin(),
        ]);
    }

    /**
     * Guarda un score del juego y devuelve XP actualizado
     *
     * Auth: sesión web (cookie) O header X-Game-Code O body.code
     *
     * Payload esperado:
     * {
     *   "code": "TNSVT-XXXX",   // Opcional si usás X-Game-Code header
     *   "mode": "classic" | "survival" | "daily" | "arena" | "torneo" | "fractal" | "portfolio",
     *   "score": int,
     *   "metadata": { "asset": "BTC", "rounds": 5, "accuracy": 80, "result": "long", "xp_gained": 50 }
     * }
     */
    #[Route('/api/game/score', name: 'api_game_score', methods: ['POST'])]
    public function saveScore(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        // Intentar autenticar por X-Game-Code header o body.code si no hay sesión
        $user = $user ?? $this->authByCode($request);
        if (!$user) {
            return new JsonResponse(['error' => 'No autenticado. Envía X-Game-Code header o body.code'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['mode'], $data['score'])) {
            return new JsonResponse(['error' => 'Payload inválido'], 400);
        }

        $mode = $data['mode'];
        $score = (int) $data['score'];
        $metadata = $data['metadata'] ?? [];

        // Validar mode
        $validModes = ['classic', 'survival', 'daily', 'arena', 'torneo', 'fractal', 'portfolio', 'hist'];
        if (!in_array($mode, $validModes, true)) {
            return new JsonResponse(['error' => 'Modo inválido'], 400);
        }

        // XP ganado (con multiplicador por streak si está)
        $baseXp = max(0, (int) ($metadata['xp_gained'] ?? 0));
        $streakMult = (float) ($metadata['streak_mult'] ?? 1.0);
        $xpGained = (int) round($baseXp * $streakMult);

        // Guardar score
        $gameScore = new GameScore();
        $gameScore->setUser($user);
        $gameScore->setMode($mode);
        $gameScore->setScore($score);
        $gameScore->setXpGained($xpGained);
        $gameScore->setMetadata($metadata);
        $gameScore->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($gameScore);
        $this->em->flush();

        // Calcular XP total del user (de scores)
        $totalXp = (int) ($this->em->getRepository(GameScore::class)
            ->createQueryBuilder('g')
            ->select('SUM(g.xpGained) as total')
            ->where('g.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult() ?: 0);

        $level = $this->getUserLevel($totalXp);

        return new JsonResponse([
            'success' => true,
            'score_id' => $gameScore->getId(),
            'user_id' => $user->getId(),
            'xp_gained' => $xpGained,
            'total_xp' => $totalXp,
            'level' => $level,
            'next_level_xp' => $this->xpForLevel($level + 1),
            'rank' => $this->getRank($level),
        ]);
    }

    /**
     * Top scores del juego
     */
    #[Route('/api/game/leaderboard', name: 'api_game_leaderboard', methods: ['GET'])]
    public function leaderboard(Request $request): JsonResponse
    {
        $mode = $request->query->get('mode', 'all');
        $limit = min(100, (int) $request->query->get('limit', 50));

        $qb = $this->em->getRepository(GameScore::class)
            ->createQueryBuilder('g')
            ->select('u.id, u.code, u.name, SUM(g.xpGained) as total_xp, COUNT(g.id) as games')
            ->join('g.user', 'u')
            ->groupBy('u.id, u.code, u.name')
            ->orderBy('total_xp', 'DESC')
            ->setMaxResults($limit);

        if ($mode !== 'all') {
            $qb->andWhere('g.mode = :mode')->setParameter('mode', $mode);
        }

        $results = $qb->getQuery()->getArrayResult();

        $leaderboard = [];
        foreach ($results as $i => $row) {
            $xp = (int) $row['total_xp'];
            $leaderboard[] = [
                'rank' => $i + 1,
                'user_id' => $row['id'],
                'code' => $row['code'],
                'name' => $row['name'],
                'xp' => $xp,
                'games' => (int) $row['games'],
                'level' => $this->getUserLevel($xp),
                'rank_tier' => $this->getRank($this->getUserLevel($xp)),
            ];
        }

        return new JsonResponse([
            'leaderboard' => $leaderboard,
            'mode' => $mode,
        ]);
    }

    /**
     * Estadísticas del user en el juego
     */
    #[Route('/api/game/my-stats', name: 'api_game_my_stats', methods: ['GET'])]
    public function myStats(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return new JsonResponse(['error' => 'No autenticado'], 401);
        }

        $byMode = $this->em->getRepository(GameScore::class)
            ->createQueryBuilder('g')
            ->select('g.mode, COUNT(g.id) as games, SUM(g.score) as total_score, SUM(g.xpGained) as total_xp, AVG(g.score) as avg_score, MAX(g.score) as best_score')
            ->where('g.user = :user')
            ->groupBy('g.mode')
            ->setParameter('user', $user)
            ->getQuery()
            ->getArrayResult();

        $totalGames = 0;
        $totalXp = 0;
        $bestScore = 0;
        foreach ($byMode as $m) {
            $totalGames += (int) $m['games'];
            $totalXp += (int) $m['total_xp'];
            $bestScore = max($bestScore, (int) $m['best_score']);
        }

        $level = $this->getUserLevel($totalXp);
        $recentScores = $this->em->getRepository(GameScore::class)
            ->createQueryBuilder('g')
            ->where('g.user = :user')
            ->setParameter('user', $user)
            ->orderBy('g.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $recent = [];
        foreach ($recentScores as $s) {
            $recent[] = [
                'mode' => $s->getMode(),
                'score' => $s->getScore(),
                'xp_gained' => $s->getXpGained(),
                'metadata' => $s->getMetadata(),
                'created_at' => $s->getCreatedAt()->format('c'),
            ];
        }

        return new JsonResponse([
            'total_games' => $totalGames,
            'total_xp' => $totalXp,
            'best_score' => $bestScore,
            'level' => $level,
            'next_level_xp' => $this->xpForLevel($level + 1),
            'rank' => $this->getRank($level),
            'by_mode' => $byMode,
            'recent_scores' => $recent,
        ]);
    }

    /**
     * Renderiza la página del juego (HTML standalone) - REMOVIDO
     * El juego ahora es una app Android separada (com.tnsvt.game) en game-app/
     * Los endpoints /api/game/* siguen funcionando para futura sincronización de XP
     */
    /*
    #[Route('/game', name: 'game_index', methods: ['GET'])]
    public function gamePage(): Response
    {
        $htmlPath = $this->getParameter('kernel.project_dir') . '/public/game/index.html';
        if (!file_exists($htmlPath)) {
            throw $this->createNotFoundException('Game not found');
        }
        $content = file_get_contents($htmlPath);
        $response = new Response($content);
        $response->headers->set('Content-Type', 'text/html; charset=utf-8');
        return $response;
    }
    */

    // === HELPERS ===

    /**
     * Autentica un user externo por código (X-Game-Code header o body.code)
     * Usado por el T.N.S.V.T Market app (com.tnsvt.game) que no comparte cookies con TNSVT
     */
    /**
     * Devuelve el estado VIP del usuario desde el servidor.
     * Reemplaza la validación client-side que podía ser falsificada.
     */
    #[Route('/api/game/vip-status', name: 'api_game_vip_status', methods: ['GET'])]
    public function vipStatus(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $user ?? $this->authByCode($request);

        if (!$user) {
            return new JsonResponse([
                'isVip' => false,
                'vipUntil' => null,
            ]);
        }

        return new JsonResponse([
            'isVip' => $user->isVip(),
            'vipUntil' => $user->getVipUntil()
                ? $user->getVipUntil()->format('Y-m-d\TH:i:s.v\Z')
                : null,
        ]);
    }

    /**
     * Activa VIP para un usuario (solo admin).
     * POST /api/game/vip-activate  { code: "XXXX", days: 30 }
     */
    #[Route('/api/game/vip-activate', name: 'api_game_vip_activate', methods: ['POST'])]
    public function vipActivate(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = json_decode($request->getContent(), true);
        $targetCode = strtoupper(trim($data['code'] ?? ''));
        $days = (int) ($data['days'] ?? 30);

        if (empty($targetCode) || $days < 1 || $days > 365) {
            return new JsonResponse(['error' => 'Parámetros inválidos'], 400);
        }

        $targetUser = $this->em->getRepository(User::class)
            ->findOneBy(['code' => $targetCode, 'active' => true]);

        if (!$targetUser) {
            return new JsonResponse(['error' => 'Usuario no encontrado'], 404);
        }

        $now = new \DateTimeImmutable();
        $currentVip = $targetUser->getVipUntil();
        // Si ya tiene VIP activo, extender desde la fecha de expiración actual
        $base = ($currentVip && $currentVip > $now) ? $currentVip : $now;
        $newVipUntil = $base->modify("+{$days} days");

        $targetUser->setVipUntil($newVipUntil);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'user' => $targetCode,
            'vipUntil' => $newVipUntil->format('Y-m-d\TH:i:s.v\Z'),
            'daysAdded' => $days,
        ]);
    }

    /**
     * Week 2 - Día 1: Triple-currency economy endpoints
     * GET /api/game/economy — returns { coins, reputation, dailyLogin }
     */
    #[Route('/api/game/economy', name: 'api_game_economy', methods: ['GET'])]
    public function economy(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $user ?? $this->authByCode($request);
        if (!$user) {
            return new JsonResponse([
                'coins' => 0,
                'reputation' => 0.0,
                'dailyLogin' => null,
            ]);
        }

        return new JsonResponse([
            'coins' => $user->getCoins(),
            'reputation' => $user->getReputation(),
            'dailyLogin' => $user->getDailyLogin(),
        ]);
    }

    /**
     * Week 2 - Día 1: Sync local currency changes back to server
     * POST /api/game/economy/sync
     * Body: { coinsDelta: 100, reputationDelta: 0.5, dailyLogin: {...} }
     *
     * NOTE: In a full production system this would be a wallet-style tx log
     * (every coin gain has a `reason` and is auditable). For Week 2 Día 1 we
     * use delta-sync to keep changes additive without breaking older clients.
     */
    #[Route('/api/game/economy/sync', name: 'api_game_economy_sync', methods: ['POST'])]
    public function economySync(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $user ?? $this->authByCode($request);
        if (!$user) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $coinsDelta = (int) ($data['coinsDelta'] ?? 0);
        $repDelta = (float) ($data['reputationDelta'] ?? 0);
        $dl = $data['dailyLogin'] ?? null;

        if ($coinsDelta !== 0) {
            if ($coinsDelta > 0) {
                $user->addCoins($coinsDelta);
            } else {
                // Negative: only allow if spending succeeds (validates balance)
                if (!$user->spendCoins(abs($coinsDelta))) {
                    return new JsonResponse(['error' => 'insufficient_coins'], 400);
                }
            }
        }
        if ($repDelta !== 0.0) {
            $user->addReputation($repDelta);
        }
        if (is_array($dl)) {
            $user->setDailyLogin($dl);
        }
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'coins' => $user->getCoins(),
            'reputation' => $user->getReputation(),
            'dailyLogin' => $user->getDailyLogin(),
        ]);
    }

    /**
     * Week 2 - Día 1: Admin endpoint to grant coins/reputation (testing only)
     * POST /api/game/economy/admin-grant
     * Body: { code, coins: 1000, reputation: 5.0 }
     */
    #[Route('/api/game/economy/admin-grant', name: 'api_game_economy_admin_grant', methods: ['POST'])]
    public function economyAdminGrant(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = json_decode($request->getContent(), true) ?: [];
        $targetCode = strtoupper(trim($data['code'] ?? ''));
        $coins = (int) ($data['coins'] ?? 0);
        $rep = (float) ($data['reputation'] ?? 0);

        if (empty($targetCode)) {
            return new JsonResponse(['error' => 'code_required'], 400);
        }

        $target = $this->em->getRepository(User::class)
            ->findOneBy(['code' => $targetCode, 'active' => true]);
        if (!$target) {
            return new JsonResponse(['error' => 'user_not_found'], 404);
        }

        if ($coins !== 0) $target->addCoins($coins);
        if ($rep !== 0.0) $target->addReputation($rep);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'user' => $targetCode,
            'coins' => $target->getCoins(),
            'reputation' => $target->getReputation(),
        ]);
    }

    /**
     * Week 2 - Día 7: Public profile stats by code (compare feature)
     * GET /api/profile/public?code=ABCD
     */
    #[Route('/api/profile/public', name: 'api_profile_public', methods: ['GET'])]
    public function profilePublic(Request $request): JsonResponse
    {
        $code = strtoupper(trim($request->query->get('code', '')));
        if (empty($code)) {
            return new JsonResponse(['error' => 'code_required'], 400);
        }
        $target = $this->em->getRepository(User::class)
            ->findOneBy(['code' => $code, 'active' => true]);
        if (!$target) {
            return new JsonResponse(['error' => 'not_found'], 404);
        }
        $xp = $this->getUserXp($target);
        return new JsonResponse([
            'name' => $target->getName(),
            'code' => $target->getCode(),
            'xp' => $xp,
            'coins' => $target->getCoins(),
            'reputation' => $target->getReputation(),
            'rank' => $this->getRank($this->getUserLevel($xp)),
            'avatarColor' => $target->getAvatarColor(),
            'emoji' => '👤',
        ]);
    }

    private function authByCode(Request $request): ?User
    {
        $code = trim($request->headers->get('X-Game-Code', ''));

        if (!$code) {
            $data = json_decode($request->getContent(), true);
            if (is_array($data) && isset($data['code'])) {
                $code = trim((string) $data['code']);
            }
        }

        if (!$code) {
            return null;
        }

        return $this->em->getRepository(User::class)
            ->findOneBy(['code' => $code, 'active' => true]);
    }

    private function getUserXp(User $user): int
    {
        return (int) ($this->em->getRepository(GameScore::class)
            ->createQueryBuilder('g')
            ->select('SUM(g.xpGained) as total')
            ->where('g.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult() ?: 0);
    }

    private function xpForLevel(int $level): int
    {
        return (int) (100 * pow(max(1, $level), 1.5));
    }

    private function getUserLevel(int $xp): int
    {
        $level = 1;
        while ($this->xpForLevel($level + 1) <= $xp && $level < 100) {
            $level++;
        }
        return $level;
    }

    private function getRank(int $level): string
    {
        return match(true) {
            $level >= 50 => 'ORÁCULO',
            $level >= 30 => 'MAESTRO',
            $level >= 20 => 'ESTRATEGA',
            $level >= 10 => 'OPERADOR',
            $level >= 5  => 'APRENDIZ',
            default      => 'NOVICIO',
        };
    }
}
