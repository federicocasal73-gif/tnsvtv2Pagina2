<?php

namespace App\Controller\Api;

use App\Entity\JournalEntry;
use App\Entity\PropFirmAccount;
use App\Entity\TradingAccount;
use App\Repository\JournalEntryRepository;
use App\Repository\PropFirmAccountRepository;
use App\Repository\PropFirmRepository;
use App\Repository\TradingAccountRepository;
use App\Repository\UserRepository;
use App\Service\ApiKeyService;
use App\Service\PropFirmRuleChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/sync')]
class SyncTradeController extends AbstractController
{
    private const MAX_ACCOUNT_NAME_LEN = 50;

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepo,
        private JournalEntryRepository $journalEntryRepo,
        private PropFirmRepository $propFirmRepo,
        private PropFirmAccountRepository $pfaRepo,
        private TradingAccountRepository $tradingAccountRepo,
        private ApiKeyService $apiKeyService,
        private PropFirmRuleChecker $ruleChecker,
    ) {}

    private function getUserFromRequest(Request $request): ?\App\Entity\User
    {
        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) return $user;

        $code = trim($request->headers->get('X-Game-Code', ''));
        if ($code) return $this->userRepo->findByCode($code);

        $data = json_decode($request->getContent(), true);
        if (is_array($data) && !empty($data['user_code'])) {
            return $this->userRepo->findByCode(trim($data['user_code']));
        }

        return null;
    }

    private function getUserFromApiKey(Request $request): ?\App\Entity\User
    {
        $apiKey = trim($request->headers->get('X-API-Key', ''));
        if (!$apiKey) return null;

        return $this->apiKeyService->validate($apiKey);
    }

    // ──────────────────────────────────────────────
    //  POST /api/sync/trade  — Recibe trade del EA
    // ──────────────────────────────────────────────
    #[Route('/trade', name: 'api_sync_trade', methods: ['POST'])]
    public function trade(Request $request): JsonResponse
    {
        $user = $this->getUserFromApiKey($request);
        if (!$user) {
            return $this->json(['error' => 'API Key inválida'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'Body inválido'], 400);
        }

        if (empty($data['symbol']) || empty($data['type'])) {
            return $this->json(['error' => 'symbol y type requeridos'], 400);
        }

        // 1. Resolver o crear TradingAccount
        $account = $this->resolveTradingAccount($user, $data);

        // 2. Crear JournalEntry
        $entry = new JournalEntry();
        $entry->setUserCode($user->getCode());
        $entry->setAsset(strtoupper($data['symbol']));
        $entry->setDirection(strtoupper($data['type']) === 'BUY' ? 'BUY' : 'SELL');
        $entry->setDate($data['open_time'] ?? 'now');
        $entry->setEntry((string) ($data['open_price'] ?? ''));
        $entry->setSl(isset($data['sl']) ? (string) $data['sl'] : null);
        $entry->setTp(isset($data['tp']) ? (string) $data['tp'] : null);
        $entry->setPnl($data['profit'] ?? null);
        $entry->setRatio(null);

        $profit = (float) ($data['profit'] ?? 0);
        $entry->setResult($profit > 0 ? 'WIN' : ($profit < 0 ? 'LOSS' : 'BE'));

        if ($account) {
            $entry->setAccountId((string) $account->getId());
        }

        $notes = [];
        if (!empty($data['broker'])) $notes[] = 'Broker: ' . $data['broker'];
        if (!empty($data['platform'])) $notes[] = 'Platform: ' . $data['platform'];
        if (!empty($data['prop_firm'])) $notes[] = 'Prop Firm: ' . $data['prop_firm'];
        if (!empty($data['ticket'])) $notes[] = 'Ticket: ' . $data['ticket'];
        $entry->setNotes(!empty($notes) ? implode(' | ', $notes) : null);

        $entry->setTags(implode(' ', array_filter([
            'ea-sync',
            $data['platform'] ?? null,
            $data['prop_firm'] ?? null,
        ])));

        $this->em->persist($entry);

        // 3. Prop firm account + reglas
        $pfStatus = null;
        $alerts = [];
        $propFirmCode = $data['prop_firm'] ?? null;
        $brokerName = $data['broker'] ?? null;
        $platform = $data['platform'] ?? null;

        if ($propFirmCode && $account) {
            $propFirm = $this->propFirmRepo->findByCode($propFirmCode);
            if ($propFirm) {
                $pfAccount = $this->pfaRepo->findByUserAndAccount($user, $account);

                if (!$pfAccount) {
                    $pfAccount = new PropFirmAccount();
                    $pfAccount->setUser($user);
                    $pfAccount->setTradingAccount($account);
                    $pfAccount->setPropFirm($propFirm);
                    $pfAccount->setAccountSize((string) ($data['account_size'] ?? 10000));
                    $pfAccount->setPeakBalance((string) ($data['balance'] ?? 0));
                    $pfAccount->setCurrentBalance((string) ($data['balance'] ?? 0));
                    $pfAccount->setStartedAt(new \DateTimeImmutable());
                    $this->em->persist($pfAccount);
                }

                $alerts = $this->ruleChecker->checkTrade($entry, $pfAccount);
                $pfStatus = $this->ruleChecker->getStatus($pfAccount);
            }
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'trade_id' => $entry->getId(),
            'prop_firm' => $pfStatus,
            'alerts' => array_map(fn($a) => [
                'type' => $a->getAlertType(),
                'severity' => $a->getSeverity(),
                'message' => $a->getMessage(),
            ], $alerts),
        ], 201);
    }

    // ──────────────────────────────────────────────
    //  POST /api/sync/keys  — Generar API Key
    // ──────────────────────────────────────────────
    #[Route('/keys', name: 'api_sync_keys_create', methods: ['POST'])]
    public function createKey(Request $request): JsonResponse
    {
        $user = $this->getUserFromRequest($request);
        if (!$user) {
            return $this->json(['error' => 'No autorizado. Enviá X-Game-Code header.'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $label = trim((string) ($data['label'] ?? ''));
        if ($label === '') {
            return $this->json(['error' => 'label requerido'], 400);
        }

        $result = $this->apiKeyService->generate($user, $label);

        return $this->json([
            'success' => true,
            'key' => $result['key'],
            'prefix' => $result['prefix'],
            'label' => $result['label'],
            'warning' => 'Guardá esta clave ahora. No se mostrará nuevamente.',
        ], 201);
    }

    // ──────────────────────────────────────────────
    //  GET /api/sync/keys  — Listar API Keys
    // ──────────────────────────────────────────────
    #[Route('/keys', name: 'api_sync_keys_list', methods: ['GET'])]
    public function listKeys(Request $request): JsonResponse
    {
        $user = $this->getUserFromRequest($request);
        if (!$user) {
            return $this->json(['error' => 'No autorizado'], 401);
        }

        return $this->json([
            'success' => true,
            'keys' => $this->apiKeyService->listKeys($user),
        ]);
    }

    // ──────────────────────────────────────────────
    //  DELETE /api/sync/keys/{id}  — Revocar API Key
    // ──────────────────────────────────────────────
    #[Route('/keys/{id}', name: 'api_sync_keys_revoke', methods: ['DELETE'])]
    public function revokeKey(int $id, Request $request): JsonResponse
    {
        $user = $this->getUserFromRequest($request);
        if (!$user) {
            return $this->json(['error' => 'No autorizado'], 401);
        }

        if ($this->apiKeyService->revoke($id, $user)) {
            return $this->json(['success' => true, 'message' => 'API Key revocada']);
        }

        return $this->json(['error' => 'No encontrada o no autorizado'], 404);
    }

    // ──────────────────────────────────────────────
    //  GET /api/sync/trades  — Listar trades sync
    // ──────────────────────────────────────────────
    #[Route('/trades', name: 'api_sync_trades_list', methods: ['GET'])]
    public function listTrades(Request $request): JsonResponse
    {
        $user = $this->getUserFromApiKey($request)
            ?? $this->getUserFromRequest($request);

        if (!$user) {
            return $this->json(['error' => 'No autorizado'], 401);
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = max(1, min(100, (int) $request->query->get('per_page', '50')));

        $entries = $this->journalEntryRepo->findBy(
            ['userCode' => $user->getCode()],
            ['createdAt' => 'DESC'],
            $perPage,
            ($page - 1) * $perPage
        );

        return $this->json([
            'success' => true,
            'page' => $page,
            'per_page' => $perPage,
            'trades' => array_map(fn(JournalEntry $e) => [
                'id' => $e->getId(),
                'asset' => $e->getAsset(),
                'direction' => $e->getDirection(),
                'entry' => $e->getEntry(),
                'sl' => $e->getSl(),
                'tp' => $e->getTp(),
                'result' => $e->getResult(),
                'pnl' => $e->getPnl(),
                'notes' => $e->getNotes(),
                'tags' => $e->getTags(),
                'date' => $e->getDate()->format('c'),
                'created_at' => $e->getCreatedAt()->format('c'),
            ], $entries),
        ]);
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────
    private function resolveTradingAccount(\App\Entity\User $user, array $data): ?TradingAccount
    {
        $broker = $data['broker'] ?? 'MT Broker';
        $platform = $data['platform'] ?? 'MT';

        $accountName = $broker . ' (' . $platform . ')';
        if (mb_strlen($accountName) > self::MAX_ACCOUNT_NAME_LEN) {
            $accountName = mb_substr($accountName, 0, self::MAX_ACCOUNT_NAME_LEN - 3) . '...';
        }

        $existing = $this->tradingAccountRepo->findActiveByUser($user);
        foreach ($existing as $acc) {
            if ($acc->getName() === $accountName) {
                return $acc;
            }
        }

        $balance = (float) ($data['balance'] ?? 10000);

        $account = new TradingAccount();
        $account->setUser($user);
        $account->setName($accountName);
        $account->setAccountSize(max(100, $balance));
        $account->setColor('#00e5b0');
        $account->setIcon('🔌');
        $account->setSortOrder(count($existing));

        $this->em->persist($account);
        return $account;
    }
}
